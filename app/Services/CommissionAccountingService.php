<?php

namespace App\Services;

use App\Models\{ChartOfAccount, Commission, JournalEntry};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommissionAccountingService
{
    public function accrue(Commission $commission, $date, $userId)
    {
        return DB::transaction(function () use ($commission, $date, $userId) {
            $commission = Commission::whereKey($commission->id)->lockForUpdate()->firstOrFail();
            if (!in_array($commission->status, ['pending', 'approved'], true)) $this->fail('Only pending or approved commissions can be accrued.');
            if ($entry = $commission->accrualJournal()->first()) return $this->posted($entry);
            if ((float) $commission->commission_amount <= 0) $this->fail('Commission must be at least PKR 0.01.');
            $expense = $this->account('6500', 'expense');
            if ($expense->children()->exists()) $this->fail('Sales Commission Expense (6500) must be a posting account without children.');
            $payable = $this->account('2200', 'liability');
            $this->notBefore($date, $commission->booking->booking_date->toDateString());
            if ($commission->approved_date) $this->notBefore($date, $commission->approved_date->toDateString());
            $entry = $this->entry('commission_approval', $commission->id, $date, 'Commission #'.$commission->id.' approved', $userId);
            $this->line($entry, $commission, $expense->id, $commission->commission_amount, '0.00');
            $this->line($entry, $commission, $payable->id, '0.00', $commission->commission_amount);
            return $entry;
        });
    }

    public function pay(Commission $commission, $date, $accountId, $userId, $requestKey)
    {
        return DB::transaction(function () use ($commission, $date, $accountId, $userId, $requestKey) {
            $commission = Commission::whereKey($commission->id)->lockForUpdate()->firstOrFail();
            if (!in_array($commission->status, ['approved', 'paid'], true)) $this->fail('Approve the commission before paying it.');
            $previousRequest = \App\Models\CommissionPayment::where('request_key', $requestKey)->first();
            if ($previousRequest) {
                if ((int) $previousRequest->commission_id !== (int) $commission->id || $previousRequest->reversed_at) $this->fail('This payment request was already used or reversed. Start a new payment.');
                if ((int) $previousRequest->cash_bank_account_id !== (int) $accountId || $previousRequest->payment_date->toDateString() !== $date) $this->fail('A payment retry must keep the original date and cash/bank account.');
                return $previousRequest;
            }
            if ($payment = $commission->payments()->whereNull('reversed_at')->first()) return $payment;
            if ($commission->status !== 'approved') $this->fail('The paid commission has no active payment record. Reconcile it first.');
            $accrual = $commission->accrualJournal()->first();
            if (!$accrual) $this->fail('Post this historical commission approval to accounting before recording its payment.');
            $this->posted($accrual);
            if ($accrual->reversal()->exists()) $this->fail('The commission approval journal has already been reversed.');
            $this->notBefore($date, $accrual->entry_date->toDateString());
            $this->notBefore($date, $commission->payments()->max('reversal_date'));
            $cash = app(PaymentAccountingService::class)->accounts()->firstWhere('id', $accountId);
            if (!$cash) $this->fail('Choose an active cash/bank posting account.');
            $payable = $this->account('2200', 'liability');
            $payment = $commission->payments()->create([
                'cash_bank_account_id' => $cash->id, 'request_key' => $requestKey, 'amount' => $commission->commission_amount,
                'payment_date' => $date, 'paid_by' => $userId,
            ]);
            $entry = $this->entry('commission_payment', $payment->id, $date, 'Commission #'.$commission->id.' payment #'.$payment->id, $userId);
            $this->line($entry, $commission, $payable->id, $payment->amount, '0.00');
            $this->line($entry, $commission, $cash->id, '0.00', $payment->amount);
            return $payment;
        });
    }

    public function reversePayment(Commission $commission, $date, $reason, $userId, $paymentId = null)
    {
        return DB::transaction(function () use ($commission, $date, $reason, $userId, $paymentId) {
            $commission = Commission::whereKey($commission->id)->lockForUpdate()->firstOrFail();
            if ($commission->status !== 'paid') $this->fail('Only paid commissions can have their payment reversed.');
            $payment = $commission->payments()->whereNull('reversed_at')->lockForUpdate()->first();
            if (!$payment) {
                if (!$commission->accrualJournal()->exists() && !$commission->payments()->exists()) return null;
                $this->fail('The active commission payment is missing. Reconcile it first.');
            }
            if ((int) $paymentId !== (int) $payment->id) $this->fail('The active payment has changed. Refresh the commission before reversing.');
            $original = $payment->journalEntry()->first();
            if (!$original) $this->fail('The commission payment journal is missing.');
            $entry = $this->reverseEntry($original, 'commission_payment_reverse', $payment->id, $date, $reason, $userId);
            $payment->update(['reversed_at' => now(), 'reversal_date' => $date, 'reversed_by' => $userId, 'reversal_reason' => $reason]);
            return $entry;
        });
    }

    public function cancel(Commission $commission, $date, $reason, $userId)
    {
        return DB::transaction(function () use ($commission, $date, $reason, $userId) {
            $commission = Commission::whereKey($commission->id)->lockForUpdate()->firstOrFail();
            if ($commission->status === 'paid' || $commission->payments()->whereNull('reversed_at')->exists()) $this->fail('Reverse the commission payment before cancelling.');
            $original = $commission->accrualJournal()->first();
            if (!$original) return null; // Unposted legacy/pending commission: no unmatched reversal.
            $this->notBefore($date, $commission->payments()->max('reversal_date'));
            return $this->reverseEntry($original, 'commission_cancel', $commission->id, $date, $reason, $userId);
        });
    }

    private function reverseEntry(JournalEntry $original, $type, $sourceId, $date, $reason, $userId)
    {
        $this->posted($original);
        if ($entry = $original->reversal()->first()) return $entry;
        $this->notBefore($date, $original->entry_date->toDateString());
        $entry = $this->entry($type, $sourceId, $date, Str::limit('Reversal '.$original->entry_number.': '.$reason, 500, ''), $userId, $original->id);
        foreach ($original->lines as $line) $entry->lines()->create([
            'chart_of_account_id' => $line->chart_of_account_id, 'debit' => $line->credit, 'credit' => $line->debit,
            'project_id' => $line->project_id, 'customer_id' => $line->customer_id, 'description' => $line->description,
        ]);
        return $entry;
    }

    private function entry($type, $sourceId, $date, $description, $userId, $reverses = null)
    {
        $period = app(AccountingPeriodService::class)->requireOpen($date);
        $entry = JournalEntry::create([
            'entry_number' => 'TMP-'.Str::uuid(), 'entry_date' => $date, 'description' => $description,
            'accounting_period_id' => $period->id, 'source_type' => $type, 'source_id' => $sourceId,
            'reverses_entry_id' => $reverses, 'status' => 'posted', 'created_by' => $userId, 'posted_by' => $userId, 'posted_at' => now(),
        ]);
        $entry->update(['entry_number' => 'JE-'.str_pad($entry->id, 8, '0', STR_PAD_LEFT)]);
        return $entry;
    }

    private function line($entry, $commission, $accountId, $debit, $credit)
    {
        $entry->lines()->create([
            'chart_of_account_id' => $accountId, 'debit' => $debit, 'credit' => $credit,
            'project_id' => $commission->booking->property->project_id, 'customer_id' => $commission->booking->customer_id,
            'description' => 'Commission #'.$commission->id.' / Agent #'.$commission->agent_id,
        ]);
    }

    private function account($code, $type)
    {
        $account = ChartOfAccount::where('code', $code)->where('account_type', $type)->where('is_system', true)->where('is_active', true)->first();
        if (!$account) $this->fail('Activate system account '.$code.' before posting commissions.');
        return $account;
    }

    private function posted($entry)
    {
        if ($entry->status !== 'posted') $this->fail('The source journal must be posted. Reconcile it first.');
        return $entry;
    }

    private function notBefore($date, $minimum)
    {
        if (!$minimum) return;
        $minimum = \Carbon\Carbon::parse($minimum)->toDateString();
        if ($date < $minimum) $this->fail('Accounting date must be on or after '.$minimum.'.');
    }

    private function fail($message)
    {
        throw ValidationException::withMessages(['accounting' => [$message]]);
    }
}
