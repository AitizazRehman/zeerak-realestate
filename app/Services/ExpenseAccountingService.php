<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Expense;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExpenseAccountingService
{
    public function accounts()
    {
        return ChartOfAccount::whereIn('account_type', ['expense', 'cost_of_sales'])
            ->where('is_active', true)->where('is_control_account', false)
            ->where('allow_manual_posting', true)->doesntHave('children')->orderBy('code')->get();
    }

    public function post(Expense $expense, $userId)
    {
        return DB::transaction(function () use ($expense, $userId) {
            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            if ($expense->reversed_at) $this->fail('Reversed expenses cannot be posted.');
            $existing = $expense->journalEntry()->first();
            if ($existing) {
                if ($existing->status !== 'posted') $this->fail('The existing expense journal is not posted. Reconcile it first.');
                return $existing;
            }
            $debit = $this->accounts()->firstWhere('id', $expense->expense_account_id);
            if (!$debit) $this->fail('Choose an active expense or cost-of-sales posting account.');
            $credit = app(PaymentAccountingService::class)->accounts()->firstWhere('id', $expense->cash_bank_account_id);
            if (!$credit) $this->fail('Choose an active cash or bank posting account under Cash & Bank (1100).');
            if ((float) $expense->amount <= 0) $this->fail('The expense amount must be positive.');
            $period = app(AccountingPeriodService::class)->requireOpen($expense->expense_date);
            $entry = $this->entry([
                'entry_date' => $expense->expense_date->toDateString(), 'accounting_period_id' => $period->id,
                'description' => 'Expense '.$expense->expense_number.': '.$expense->description,
                'source_type' => 'expense', 'source_id' => $expense->id,
            ], $userId);
            $dimensions = ['project_id' => $expense->project_id, 'customer_id' => null, 'description' => $expense->expense_number];
            $entry->lines()->create($dimensions + ['chart_of_account_id' => $debit->id, 'debit' => $expense->amount, 'credit' => '0.00']);
            $entry->lines()->create($dimensions + ['chart_of_account_id' => $credit->id, 'debit' => '0.00', 'credit' => $expense->amount]);
            return $entry;
        });
    }

    public function reverse(Expense $expense, $userId, $date, $reason)
    {
        return DB::transaction(function () use ($expense, $userId, $date, $reason) {
            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $original = $expense->journalEntry()->lockForUpdate()->first();
            if (!$original) {
                if (!$expense->expense_account_id && !$expense->cash_bank_account_id) return null;
                $this->fail('The original expense journal is missing. Reconcile it before reversing.');
            }
            $existing = $original->reversal()->first();
            if ($existing) return $existing;
            if ($original->status !== 'posted') $this->fail('Only posted expense journals can be reversed.');
            if ($date < $original->entry_date->toDateString()) $this->fail('The reversal date cannot precede the expense.');
            $period = app(AccountingPeriodService::class)->requireOpen($date);
            $entry = $this->entry([
                'entry_date' => $date, 'accounting_period_id' => $period->id,
                'description' => Str::limit('Reversal '.$expense->expense_number.': '.$reason, 500, ''),
                'source_type' => 'expense_reversal', 'source_id' => $expense->id,
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
