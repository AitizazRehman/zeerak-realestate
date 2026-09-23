<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\Expense;
use App\Models\FinancialAudit;
use App\Models\FinancialDocument;
use App\Models\Installment;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

        if ($type === 'payment') {
            $entity = $model::with('booking.property.project')->findOrFail($id);
            $branchId = optional(optional(optional($entity->booking)->property)->project)->branch_id;
        } elseif ($type === 'installment') {
            $entity = $model::with('booking.property.project')->findOrFail($id);
            $branchId = optional(optional(optional($entity->booking)->property)->project)->branch_id;
        } elseif ($type === 'commission') {
            $entity = $model::with('booking.property.project')->findOrFail($id);
            $branchId = optional(optional(optional($entity->booking)->property)->project)->branch_id;
        } else {
            $entity = $model::with(['project', 'property.project'])->findOrFail($id);
            $branchId = $entity->project ? $entity->project->branch_id : optional(optional($entity->property)->project)->branch_id;
        }

        if (!$this->canAccessAllBranches()) {
            if (!$branchId) abort(403, 'This financial record is not assigned to your branch.');
            $this->ensureBranchAccess($branchId);
        }

        return [$entity, $branchId];
    }

    private function ensureDocumentAccess(FinancialDocument $document, $action)
    {
        $this->ensurePermission($document->entity_type, $action);
        return $this->resolveEntity($document->entity_type, $document->entity_id);
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
        if (!$file->isValid()) abort(422, 'The uploaded document is invalid.');

        $path = $file->store('financial-documents/'.$type.'/'.$id, 'local');
        if (!$path) abort(500, 'Document could not be stored.');

        try {
            $document = FinancialDocument::create([
                'entity_type' => $type,
                'entity_id' => $entity->id,
                'document_type' => $data['document_type'],
                'name' => $file->getClientOriginalName(),
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

        if (!$document->file_path || !Storage::disk('local')->exists($document->file_path)) {
            abort(404, 'Document file not found.');
        }

        return Storage::disk('local')->download($document->file_path, $document->name);
    }

    public function destroy(FinancialDocument $document)
    {
        list($entity, $branchId) = $this->ensureDocumentAccess($document, 'delete');

        $before = $document->toArray();
        $path = $document->file_path;
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

        if ($path) Storage::disk('local')->delete($path);

        return response()->json(['message' => 'Document deleted successfully.']);
    }
}
