<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankStatementImport extends Model
{
    protected $fillable = [
        'bank_account_id',
        'branch_id',
        'original_filename',
        'stored_path',
        'file_type',
        'file_hash',
        'header_row',
        'column_mapping',
        'status',
        'total_rows',
        'imported_rows',
        'duplicate_rows',
        'skipped_rows',
        'statement_opening_balance',
        'statement_closing_balance',
        'notes',
        'error_message',
        'uploaded_by',
        'imported_at',
    ];

    protected $casts = [
        'column_mapping' => 'array',
        'statement_opening_balance' => 'decimal:2',
        'statement_closing_balance' => 'decimal:2',
        'imported_at' => 'datetime',
    ];

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function transactions()
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function reconciliation()
    {
        return $this->hasOne(BankReconciliation::class);
    }
}
