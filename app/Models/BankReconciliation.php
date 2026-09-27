<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankReconciliation extends Model
{
    protected $fillable = [
        'bank_account_id',
        'branch_id',
        'bank_statement_import_id',
        'from_date',
        'to_date',
        'statement_opening_balance',
        'statement_closing_balance',
        'gl_balance',
        'outstanding_deposits',
        'outstanding_payments',
        'unmatched_bank_deposits',
        'unmatched_bank_withdrawals',
        'adjusted_bank_balance',
        'difference',
        'status',
        'notes',
        'created_by',
        'completed_by',
        'completed_at',
        'reopened_by',
        'reopened_at',
        'reopen_reason',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'statement_opening_balance' => 'decimal:2',
        'statement_closing_balance' => 'decimal:2',
        'gl_balance' => 'decimal:2',
        'outstanding_deposits' => 'decimal:2',
        'outstanding_payments' => 'decimal:2',
        'unmatched_bank_deposits' => 'decimal:2',
        'unmatched_bank_withdrawals' => 'decimal:2',
        'adjusted_bank_balance' => 'decimal:2',
        'difference' => 'decimal:2',
        'completed_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function statementImport()
    {
        return $this->belongsTo(BankStatementImport::class, 'bank_statement_import_id');
    }

    public function matches()
    {
        return $this->hasMany(BankReconciliationMatch::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function reopenedBy()
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }
}
