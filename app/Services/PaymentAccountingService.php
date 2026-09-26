<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentAccountingService
{
    public function accounts()
    {
        $accounts = ChartOfAccount::where('account_type', 'asset')->where('is_active', true)
            ->withCount('children')->orderBy('code')->get();
        $byId = $accounts->keyBy('id');
        return $accounts->filter(function ($account) use ($byId) {
            if ($account->is_control_account || $account->children_count) return false;
            $parent = $account->parent_id;
            $seen = [];
            while ($parent && !isset($seen[$parent])) {
                $seen[$parent] = true;
                $node = $byId->get($parent);
                if (!$node) return false;
                if ($node->code === '1100' && $node->is_system) return true;
                $parent = $node->parent_id;
            }
            return false;
        })->values();
    }

    public function post(Payment $payment, $userId)
    {
        return DB::transaction(function () use ($payment, $userId) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== 'verified') $this->fail('Only verified payments can be posted.');
            $existing = $payment->journalEntry()->first();
            if ($existing) {
                if ($existing->status !== 'posted') $this->fail('The existing payment journal is not posted. Reconcile it before continuing.');
                return $existing;
            }
            $account = $this->accounts()->firstWhere('id', $payment->cash_bank_account_id);
            if (!$account) $this->fail('Choose an active cash or bank posting account under Cash & Bank (1100).');
            $advance = ChartOfAccount::where('code', '2300')->where('is_system', true)
                ->where('account_type', 'liability')->where('is_active', true)->first();
            if (!$advance) $this->fail('Customer Advances (2300) must be active before recording payments.');
            if ((float) $payment->amount <= 0) $this->fail('The payment amount must be positive.');

            $period = app(AccountingPeriodService::class)->requireOpen($payment->payment_date);
            $entry = $this->entry([
                'entry_date' => $payment->payment_date->toDateString(),
                'description' => 'Customer collection '.$payment->receipt_number,
                'accounting_period_id' => $period->id, 'source_type' => 'payment', 'source_id' => $payment->id,
            ], $userId);
            $dimensions = [
                'project_id' => $payment->booking->property->project_id,
                'customer_id' => $payment->customer_id,
                'description' => $payment->receipt_number,
            ];
            $entry->lines()->create($dimensions + ['chart_of_account_id' => $account->id, 'debit' => $payment->amount, 'credit' => '0.00']);
            $entry->lines()->create($dimensions + ['chart_of_account_id' => $advance->id, 'debit' => '0.00', 'credit' => $payment->amount]);
            return $entry;
        });
    }

    public function reverse(Payment $payment, $userId, $date, $reason)
    {
        return DB::transaction(function () use ($payment, $userId, $date, $reason) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $original = $payment->journalEntry()->lockForUpdate()->first();
            if (!$original) {
                // Legacy receipts were never journaled; do not create an unmatched reversal.
                if (!$payment->cash_bank_account_id) return null;
                $this->fail('The original payment journal is missing. Reconcile it before reversing.');
            }
            $existing = $original->reversal()->first();
            if ($existing) return $existing;
            if ($original->status !== 'posted') $this->fail('The original payment journal must be posted.');
            if ($date < $original->entry_date->toDateString()) $this->fail('The reversal date cannot precede the payment.');
            $period = app(AccountingPeriodService::class)->requireOpen($date);
            $entry = $this->entry([
                'entry_date' => $date, 'description' => Str::limit('Reversal '.$payment->receipt_number.': '.$reason, 500, ''),
                'accounting_period_id' => $period->id, 'source_type' => 'payment_reversal', 'source_id' => $payment->id,
                'reverses_entry_id' => $original->id,
            ], $userId);
            foreach ($original->lines as $line) {
                $entry->lines()->create([
                    'chart_of_account_id' => $line->chart_of_account_id,
                    'debit' => $line->credit, 'credit' => $line->debit,
                    'project_id' => $line->project_id, 'customer_id' => $line->customer_id,
                    'description' => $line->description,
                ]);
            }
            return $entry;
        });
    }

    private function entry(array $data, $userId)
    {
        $entry = JournalEntry::create($data + [
            'entry_number' => 'TMP-'.Str::uuid(), 'status' => 'posted',
            'created_by' => $userId, 'posted_by' => $userId, 'posted_at' => now(),
        ]);
        $entry->update(['entry_number' => 'JE-'.str_pad($entry->id, 8, '0', STR_PAD_LEFT)]);
        return $entry;
    }

    private function fail($message)
    {
        throw ValidationException::withMessages(['accounting' => [$message]]);
    }
}
