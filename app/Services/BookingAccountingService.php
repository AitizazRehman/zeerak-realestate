<?php

namespace App\Services;

use App\Models\{Booking, ChartOfAccount, JournalEntry, Payment};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingAccountingService
{
    public function recognize(Booking $booking, $date, $reference, $userId)
    {
        return DB::transaction(function () use ($booking, $date, $reference, $userId) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if (!in_array($booking->status, ['confirmed', 'completed'], true)) $this->fail('Only confirmed or completed bookings can recognize revenue.');
            if ($entry = $booking->revenueJournal()->first()) {
                $this->assertPosted($entry);
                if ($entry->reversal()->exists()) $this->fail('This revenue recognition has been reversed.');
                return $entry;
            }
            $this->notBefore($date, $booking->booking_date->toDateString());
            if ((float) $booking->final_price <= 0) $this->fail('Booking value must be positive to recognize revenue.');
            $payments = $booking->payments()->where('status', 'verified')->lockForUpdate()->get();
            if (round((float) $payments->sum('amount'), 2) !== round((float) $booking->paid_amount, 2) || round((float) $booking->final_price - (float) $booking->paid_amount, 2) !== round((float) $booking->remaining_amount, 2)) $this->fail('Reconcile the booking payment totals before recognizing revenue.');
            if ((float) $booking->remaining_amount < 0) $this->fail('Booking collections exceed its value.');
            foreach ($payments as $payment) {
                $journal = $payment->journalEntry;
                if (!$journal || $journal->status !== 'posted' || $journal->reversal()->exists() || $payment->reversed_at) $this->fail('Every active receipt must have a posted, unreversed payment journal. Reconcile historical receipts before recognizing revenue.');
            }
            // Reversed receipts are excluded from allocation. Do not backdate the
            // sale across their reversal and silently omit interim receipt balances.
            foreach ($booking->payments()->withTrashed()->where('status', 'reversed')->get() as $reversed) {
                $reversal = $reversed->reversalJournal;
                $minimum = $reversal ? $reversal->entry_date->toDateString() : ($reversed->reversed_at ? $reversed->reversed_at->toDateString() : null);
                if (!$minimum) $this->fail('Reconcile the historical receipt reversal date before recognizing revenue.');
                $this->notBefore($date, $minimum);
            }
            $receivable = $this->account('1200', 'asset');
            $revenue = $this->account('4100', 'revenue');
            if ($revenue->children()->exists()) $this->fail('Property Sales (4100) must be a posting account without children.');
            $entry = $this->entry('booking_revenue', $booking->id, $date, 'Revenue '.$booking->booking_number.': '.$reference, $userId);
            $this->line($entry, $booking, $receivable->id, $booking->final_price, '0.00');
            $this->line($entry, $booking, $revenue->id, '0.00', $booking->final_price);
            foreach ($payments as $payment) $this->applyReceipt($payment, $userId);
            return $entry;
        });
    }

    // Called inside the booking/payment transaction. Collections continue to post to
    // advances, then this distinct entry clears them against the recognized sale.
    public function applyReceipt(Payment $payment, $userId)
    {
        $booking = $payment->booking;
        $recognition = $booking->revenueJournal()->first();
        if (!$recognition) return null;
        $this->assertPosted($recognition);
        if ($recognition->reversal()->exists()) $this->fail('Cannot apply receipts to reversed booking revenue.');
        if ($existing = $payment->applicationJournal()->first()) return $existing;
        $receipt = $payment->journalEntry()->first();
        if (!$receipt || $receipt->status !== 'posted' || $receipt->reversal()->exists()) $this->fail('The receipt must have a posted, unreversed journal before allocation.');
        $date = max($recognition->entry_date->toDateString(), $receipt->entry_date->toDateString());
        $advance = $this->account('2300', 'liability');
        $receivable = $this->account('1200', 'asset');
        $entry = $this->entry('booking_receipt', $payment->id, $date, 'Apply '.$payment->receipt_number.' to '.$booking->booking_number, $userId);
        $this->line($entry, $booking, $advance->id, $payment->amount, '0.00');
        $this->line($entry, $booking, $receivable->id, '0.00', $payment->amount);
        return $entry;
    }

    public function reverseReceipt(Payment $payment, $date, $reason, $userId)
    {
        $entry = $payment->applicationJournal()->first();
        if (!$entry) return null;
        return $this->reverse($entry, 'booking_receipt_reverse', $payment->id, $date, $reason, $userId);
    }

    public function cancel(Booking $booking, $date, $userId)
    {
        $original = $booking->revenueJournal()->first();
        if (!$original) return null;
        if ($booking->payments()->where('status', 'verified')->exists()) $this->fail('Reverse all booking receipts before cancelling recognized revenue.');
        foreach ($booking->payments()->withTrashed()->get() as $payment) {
            $allocation = $payment->applicationJournal;
            if (!$allocation) continue;
            $reversal = $allocation->reversal;
            if (!$reversal) $this->fail('Reverse all receipt allocations before cancelling revenue.');
            $this->notBefore($date, $reversal->entry_date->toDateString());
        }
        return $this->reverse($original, 'booking_revenue_cancel', $booking->id, $date, 'Booking cancelled', $userId);
    }

    private function reverse($original, $type, $id, $date, $reason, $userId)
    {
        $this->assertPosted($original);
        if ($entry = $original->reversal()->first()) return $entry;
        $this->notBefore($date, $original->entry_date->toDateString());
        $entry = $this->entry($type, $id, $date, 'Reversal '.$original->entry_number.': '.$reason, $userId, $original->id);
        foreach ($original->lines as $line) $entry->lines()->create([
            'chart_of_account_id'=>$line->chart_of_account_id, 'debit'=>$line->credit, 'credit'=>$line->debit,
            'project_id'=>$line->project_id, 'customer_id'=>$line->customer_id, 'description'=>$line->description,
        ]);
        return $entry;
    }

    private function entry($type, $id, $date, $description, $userId, $reverses = null)
    {
        $period = app(AccountingPeriodService::class)->requireOpen($date);
        $entry = JournalEntry::create([
            'entry_number'=>'TMP-'.Str::uuid(), 'entry_date'=>$date, 'description'=>Str::limit($description, 500, ''),
            'accounting_period_id'=>$period->id, 'source_type'=>$type, 'source_id'=>$id, 'reverses_entry_id'=>$reverses,
            'status'=>'posted', 'created_by'=>$userId, 'posted_by'=>$userId, 'posted_at'=>now(),
        ]);
        $entry->update(['entry_number'=>'JE-'.str_pad($entry->id, 8, '0', STR_PAD_LEFT)]);
        return $entry;
    }

    private function line($entry, $booking, $accountId, $debit, $credit)
    {
        $entry->lines()->create(['chart_of_account_id'=>$accountId, 'debit'=>$debit, 'credit'=>$credit,
            'project_id'=>$booking->property->project_id, 'customer_id'=>$booking->customer_id, 'description'=>$booking->booking_number]);
    }

    private function account($code, $type)
    {
        $account = ChartOfAccount::where('code', $code)->where('account_type', $type)->where('is_system', true)->where('is_active', true)->first();
        if (!$account) $this->fail('Activate system account '.$code.' before posting booking revenue.');
        return $account;
    }

    private function assertPosted($entry)
    {
        if ($entry->status !== 'posted') $this->fail('The source journal must be posted. Reconcile it first.');
    }

    private function notBefore($date, $minimum)
    {
        if ($date < $minimum) $this->fail('Accounting date must be on or after '.$minimum.'.');
    }

    private function fail($message)
    {
        throw ValidationException::withMessages(['accounting'=>[$message]]);
    }
}
