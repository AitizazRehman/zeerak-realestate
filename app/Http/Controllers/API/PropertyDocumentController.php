<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PropertyDocumentController extends Controller
{
    public function store(
        Request $request,
        Property $property
    ) {
        $request->validate([
            'document' => [
                'required',
                'file',
                'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png',
                'max:10240',
            ],

            'document_type' => [
                'nullable',
                'string',
                'max:100',
            ],
        ]);

        $file = $request->file('document');

        $path = $file->store(
            'properties/' . $property->id . '/documents',
            'public'
        );

        $document = $property->documents()->create([
            'document_type' => $request->document_type,
            'name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'uploaded_by' => auth()->id(),
        ]);

        return response()->json([
            'message' => 'Document uploaded successfully.',
            'document' => $document,
        ], 201);
    }

    public function destroy(PropertyDocument $document)
    {
        if ($document->file_path) {
            Storage::disk('public')
                ->delete($document->file_path);
        }

        $document->delete();

        return response()->json([
            'message' => 'Document deleted successfully.',
        ]);
    }
}