<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankTransaction extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'bank_account_id',
        'branch_id',
        'transaction_date',
        'value_date',
        'debit',
        'credit',
        'running_balance',
        'reference_number',
        'cheque_number',
        'external_transaction_id',
        'transaction_hash',
        'description',
        'source',
        'project_id',
        'customer_id',
        'journal_entry_id',
        'reconciliation_status',
        'match_method',
        'reconciled_at',
        'reconciled_by',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'value_date' => 'date',
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'running_balance' => 'decimal:2',
        'reconciled_at' => 'datetime',
    ];

    protected $appends = [
        'amount',
        'transaction_type',
    ];

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reconciledBy()
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getAmountAttribute()
    {
        return number_format((float) $this->debit > 0 ? (float) $this->debit : (float) $this->credit, 2, '.', '');
    }

    public function getTransactionTypeAttribute()
    {
        if ((float) $this->debit > 0) {
            return 'deposit';
        }

        if ((float) $this->credit > 0) {
            return 'withdrawal';
        }

        return null;
    }

    public function isReconciled()
    {
        return $this->reconciliation_status === 'reconciled';
    }
}
