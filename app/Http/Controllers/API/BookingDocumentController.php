<?php
namespace App\Http\Controllers\API;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Booking;
use App\Models\BookingDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BookingDocumentController extends Controller
{
    use ChecksBranchAccess;

    private function checkBooking(Booking $booking)
    {
        $booking->loadMissing('property.project');
        $this->ensureBranchAccess($booking->property->project->branch_id);
    }

    public function index(Booking $booking)
    {
        $this->checkBooking($booking);
        return response()->json($booking->documents()->with('uploader:id,name')->latest()->get());
    }

    public function store(Request $request, Booking $booking)
    {
        $this->checkBooking($booking);
        $request->validate([
            'document'=>['required','file','mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png','max:10240'],
            'document_type'=>['required','string','max:100'],
            'notes'=>['nullable','string','max:1000'],
        ]);
        $file=$request->file('document');
        $path=$file->store('bookings/'.$booking->id.'/documents', 'local');
        $document=$booking->documents()->create([
            'document_type'=>$request->document_type,
            'name'=>$file->getClientOriginalName(),
            'file_path'=>$path,
            'file_size'=>$file->getSize(),
            'mime_type'=>$file->getMimeType(),
            'uploaded_by'=>auth()->id(),
            'notes'=>$request->notes,
        ]);
        return response()->json(['message'=>'Document uploaded successfully.','document'=>$document],201);
    }

    public function download(BookingDocument $document)
    {
        $document->loadMissing('booking.property.project');
        $this->checkBooking($document->booking);
        if (!$document->file_path || !Storage::disk('local')->exists($document->file_path)) abort(404, 'Document file not found.');
        return Storage::disk('local')->download($document->file_path, $document->name);
    }

    public function destroy(BookingDocument $document)
    {
        $document->loadMissing('booking.property.project');
        $this->checkBooking($document->booking);
        if($document->file_path) Storage::disk('local')->delete($document->file_path);
        $document->delete();
        return response()->json(['message'=>'Document deleted successfully.']);
    }
}