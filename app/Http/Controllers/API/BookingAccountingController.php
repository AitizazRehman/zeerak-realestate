<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\{Booking, FinancialAudit};
use App\Services\BookingAccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingAccountingController extends Controller
{
    use ChecksBranchAccess;

    public function show(Booking $booking)
    {
        $this->ensureBranchAccess($booking->property->project->branch_id);
        return response()->json($booking->load(['revenueJournal', 'revenueCancellationJournal',
            'payments.applicationJournal', 'payments.applicationReversalJournal']));
    }

    public function recognize(Request $request, Booking $booking, BookingAccountingService $accounting)
    {
        $data = $request->validate([
            'accounting_date'=>'required|date_format:Y-m-d|before_or_equal:today',
            'reference'=>'required|string|max:300', 'confirmed'=>'required|accepted',
        ]);
        if (!trim($data['reference'])) abort(422, 'Provide a supporting recognition reference.');
        $entry = DB::transaction(function () use ($booking, $data, $accounting) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->ensureBranchAccess($booking->property->project->branch_id);
            $existing = $booking->revenueJournal()->exists();
            $entry = $accounting->recognize($booking, $data['accounting_date'], trim($data['reference']), auth()->id());
            if (!$existing) FinancialAudit::create([
                'entity_type'=>'booking', 'entity_id'=>$booking->id, 'branch_id'=>$booking->property->project->branch_id,
                'action'=>'revenue_recognized', 'user_id'=>auth()->id(), 'reason'=>$data['reference'],
                'after_data'=>['journal_entry_id'=>$entry->id, 'accounting_date'=>$data['accounting_date'], 'amount'=>$booking->final_price],
            ]);
            return $entry;
        });
        return response()->json(['message'=>'Booking revenue recognized and journaled receipts applied.', 'journal'=>$entry]);
    }
}
