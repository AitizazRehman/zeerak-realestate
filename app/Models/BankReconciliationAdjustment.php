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
        'adjustment_type',
        'amount',
        'direction',
        'description',
        'project_id',
        'customer_id',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
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
}
