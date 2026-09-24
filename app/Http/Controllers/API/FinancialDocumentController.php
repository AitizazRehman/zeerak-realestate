<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Commission;
use App\Models\Expense;
use App\Models\FinancialAudit;
use App\Models\FinancialDocument;
use App\Models\Installment;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class FinancialDocumentController extends Controller
{
    use ChecksBranchAccess;

    private function config($type)
    {
        $map = [
            'payment' => ['model' => Payment::class, 'permission' => 'payments'],
            'installment' => ['model' => Installment::class, 'permission' => 'installments'],
            'commission' => ['model' => Commission::class, 'permission' => 'commissions'],
            'expense' => ['model' => Expense::class, 'permission' => 'expenses'],
        ];

        if (!isset($map[$type])) {
            abort(404, 'Unsupported financial document type.');
        }

        return $map[$type];
    }

    private function ensurePermission($type, $action)
    {
        $config = $this->config($type);
        $user = auth()->user();
        $base = $config['permission'];

        if ($action === 'view' && $user->can($base.'.view')) return;
        if ($action === 'upload' && ($user->can($base.'.create') || $user->can($base.'.edit'))) return;
        if ($action === 'delete' && ($user->can($base.'.edit') || $user->can($base.'.delete'))) return;

        abort(403, 'You do not have permission to manage these documents.');
    }

    private function resolveEntity($type, $id)
    {
        $config = $this->config($type);
        $model = $config['model'];

        if ($type === 'payment' || $type === 'installment' || $type === 'commission') {
            $entity = $model::with('booking.property.project')->findOrFail($id);
            $branchId = optional(optional(optional($entity->booking)->property)->project)->branch_id;
        } else {
            $entity = $model::with(['project', 'property.project'])->findOrFail($id);
            $branchId = $entity->branch_id
                ?: ($entity->project
                    ? $entity->project->branch_id
                    : optional(optional($entity->property)->project)->branch_id);
        }

        if (!$this->canAccessAllBranches()) {
            if (!$branchId) {
                abort(403, 'This financial record is not assigned to your branch.');
            }

            $this->ensureBranchAccess($branchId);
        }

        return [$entity, $branchId];
    }

    private function ensureDocumentAccess(FinancialDocument $document, $action)
    {
        $this->ensurePermission($document->entity_type, $action);
        return $this->resolveEntity($document->entity_type, $document->entity_id);
    }

    private function documentDirectory($type, $id)
    {
        return 'financial-documents/'.$type.'/'.(int) $id;
    }

    private function safeOriginalName($name)
    {
        $name = str_replace('\\', '/', (string) $name);
        $name = basename($name);
        $name = str_replace(["\0", "\r", "\n"], '', $name);

        return $name !== '' ? $name : 'document';
    }

    private function ensureSafeStoredPath(FinancialDocument $document)
    {
        $path = str_replace('\\', '/', (string) $document->file_path);
        $prefix = $this->documentDirectory($document->entity_type, $document->entity_id).'/';

        if ($path === '' || strpos($path, $prefix) !== 0 || strpos($path, '../') !== false) {
            abort(500, 'Document storage path is invalid.');
        }

        return $path;
    }

    private function assertFileSignature($file)
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $path = $file->getRealPath();

        if (!$path || !is_file($path)) {
            abort(422, 'The uploaded document could not be inspected.');
        }

        $handle = fopen($path, 'rb');
        if (!$handle) {
            abort(422, 'The uploaded document could not be inspected.');
        }

        $header = fread($handle, 16);
        fclose($handle);

        if ($header === false) {
            abort(422, 'The uploaded document could not be inspected.');
        }

        $valid = false;

        if ($extension === 'pdf') {
            $valid = substr($header, 0, 5) === '%PDF-';
        } elseif ($extension === 'jpg' || $extension === 'jpeg') {
            $valid = substr($header, 0, 3) === "\xFF\xD8\xFF";
        } elseif ($extension === 'png') {
            $valid = substr($header, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A";
        } elseif ($extension === 'webp') {
            $valid = substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP';
        } elseif ($extension === 'doc' || $extension === 'xls') {
            $valid = substr($header, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
        } elseif ($extension === 'docx' || $extension === 'xlsx') {
            $valid = substr($header, 0, 4) === "PK\x03\x04"
                && $this->validOpenXmlDocument($path, $extension);
        }

        if (!$valid) {
            abort(422, 'The uploaded file contents do not match the selected file type.');
        }
    }

    private function validOpenXmlDocument($path, $extension)
    {
        if (!class_exists(ZipArchive::class)) {
            abort(500, 'PHP ZipArchive is required to validate Office documents.');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::CHECKCONS);

        if ($opened !== true) {
            return false;
        }

        $hasContentTypes = $zip->locateName('[Content_Types].xml') !== false;
        $hasExpectedFolder = $extension === 'docx'
            ? $zip->locateName('word/document.xml') !== false
            : $zip->locateName('xl/workbook.xml') !== false;

        $zip->close();

        return $hasContentTypes && $hasExpectedFolder;
    }

    public function index($type, $id)
    {
        $this->ensurePermission($type, 'view');
        $this->resolveEntity($type, $id);

        return response()->json(
            FinancialDocument::where('entity_type', $type)
                ->where('entity_id', $id)
                ->with('uploader:id,name')
                ->latest()
                ->get()
        );
    }

    public function store(Request $request, $type, $id)
    {
        $this->ensurePermission($type, 'upload');
        list($entity, $branchId) = $this->resolveEntity($type, $id);

        $data = $request->validate([
            'document' => ['required','file','mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp','max:10240'],
            'document_type' => ['required','in:receipt,invoice,voucher,bank_slip,cheque,agreement,other'],
            'notes' => ['nullable','string','max:1000'],
        ]);

        $file = $request->file('document');

        if (!$file || !$file->isValid()) {
            abort(422, 'The uploaded document is invalid.');
        }

        $this->assertFileSignature($file);

        $path = $file->store($this->documentDirectory($type, $entity->id), 'local');

        if (!$path) {
            abort(500, 'Document could not be stored.');
        }

        try {
            $document = DB::transaction(function () use ($data, $file, $path, $type, $entity, $branchId) {
                $document = FinancialDocument::create([
                    'entity_type' => $type,
                    'entity_id' => $entity->id,
                    'document_type' => $data['document_type'],
                    'name' => $this->safeOriginalName($file->getClientOriginalName()),
                    'file_path' => $path,
                    'file_size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'uploaded_by' => auth()->id(),
                    'notes' => isset($data['notes']) ? $data['notes'] : null,
                ]);

                FinancialAudit::create([
                    'entity_type' => 'financial_document',
                    'entity_id' => $document->id,
                    'branch_id' => $branchId,
                    'action' => 'created',
                    'user_id' => auth()->id(),
                    'after_data' => $document->toArray(),
                    'reason' => 'Document attached to '.$type.' #'.$entity->id,
                ]);

                return $document;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return response()->json([
            'message' => 'Document uploaded successfully.',
            'document' => $document->load('uploader:id,name'),
        ], 201);
    }

    public function download(FinancialDocument $document)
    {
        $this->ensureDocumentAccess($document, 'view');
        $path = $this->ensureSafeStoredPath($document);

        if (!Storage::disk('local')->exists($path)) {
            abort(404, 'Document file not found.');
        }

        return Storage::disk('local')->download($path, $this->safeOriginalName($document->name));
    }

    public function destroy(FinancialDocument $document)
    {
        list($entity, $branchId) = $this->ensureDocumentAccess($document, 'delete');

        $before = $document->toArray();
        $path = $this->ensureSafeStoredPath($document);
        $disk = Storage::disk('local');
        $stagedPath = null;

        if ($disk->exists($path)) {
            $stagedPath = 'financial-documents/.deleting/'.Str::random(40).'-'.basename($path);

            if (!$disk->move($path, $stagedPath)) {
                abort(500, 'Document could not be prepared for deletion.');
            }
        }

        try {
            DB::transaction(function () use ($document, $entity, $branchId, $before) {
                $document->delete();

                FinancialAudit::create([
                    'entity_type' => 'financial_document',
                    'entity_id' => $document->id,
                    'branch_id' => $branchId,
                    'action' => 'deleted',
                    'user_id' => auth()->id(),
                    'before_data' => $before,
                    'reason' => 'Document removed from '.$document->entity_type.' #'.$entity->id,
                ]);
            });
        } catch (\Throwable $e) {
            if ($stagedPath && $disk->exists($stagedPath) && !$disk->exists($path)) {
                $disk->move($stagedPath, $path);
            }

            throw $e;
        }

        if ($stagedPath && $disk->exists($stagedPath) && !$disk->delete($stagedPath)) {
            Log::warning('Financial document database deletion succeeded but staged file cleanup failed.', [
                'document_id' => $document->id,
                'file_path' => $stagedPath,
            ]);
        }

        return response()->json(['message' => 'Document deleted successfully.']);
    }
}
