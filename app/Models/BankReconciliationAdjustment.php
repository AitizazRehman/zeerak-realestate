<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankReconciliationAdjustment extends Model
{
    protected $fillable = [
        'bank_transaction_id',
        'bank_reconciliation_id',
        'offset_account_id',
        'journal_entry_id',
        'reversal_journal_entry_id',
        'adjustment_type',
        'amount',
        'direction',
        'description',
        'project_id',
        'customer_id',
        'created_by',
        'reversed_by',
        'reversed_at',
        'reversal_reason',
    ];

    protected $casts = [
        'bank_transaction_id' => 'integer',
        'bank_reconciliation_id' => 'integer',
        'offset_account_id' => 'integer',
        'journal_entry_id' => 'integer',
        'reversal_journal_entry_id' => 'integer',
        'project_id' => 'integer',
        'customer_id' => 'integer',
        'created_by' => 'integer',
        'reversed_by' => 'integer',
        'amount' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    public function bankTransaction()
    {
        return $this->belongsTo(BankTransaction::class);
    }

    public function reconciliation()
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }

    public function offsetAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'offset_account_id');
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalJournalEntry()
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversedBy()
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
