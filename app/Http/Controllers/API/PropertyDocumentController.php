<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Property;
use App\Models\PropertyDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PropertyDocumentController extends Controller
{
    use ChecksBranchAccess;

    private function checkProperty(Property $property)
    {
        $property->loadMissing('project');
        $this->ensureBranchAccess($property->project->branch_id);
    }

    public function store(Request $request, Property $property)
    {
        $this->checkProperty($property);
        $request->validate([
            'document'=>['required','file','mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png','max:10240'],
            'document_type'=>['nullable','string','max:100'],
        ]);

        $file=$request->file('document');
        if (!$file->isValid()) abort(422, 'The uploaded document is invalid.');
        $path=$file->store('properties/'.$property->id.'/documents', 'local');

        if (!$path) abort(500, 'Document could not be stored.');
        try {
            $document=$property->documents()->create([
            'document_type'=>$request->document_type,
            'name'=>$file->getClientOriginalName(),
            'file_path'=>$path,
            'file_size'=>$file->getSize(),
            'mime_type'=>$file->getMimeType(),
            'uploaded_by'=>auth()->id(),
            ]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return response()->json(['message'=>'Document uploaded successfully.','document'=>$document],201);
    }

    public function download(PropertyDocument $document)
    {
        $document->loadMissing('property.project');
        $this->checkProperty($document->property);
        if (!$document->file_path || !Storage::disk('local')->exists($document->file_path)) abort(404, 'Document file not found.');
        return Storage::disk('local')->download($document->file_path,$document->name);
    }

    public function destroy(PropertyDocument $document)
    {
        $document->loadMissing('property.project');
        $this->checkProperty($document->property);
        $path = $document->file_path;
        $document->delete();
        if($path) Storage::disk('local')->delete($path);
        return response()->json(['message'=>'Document deleted successfully.']);
    }
}
