<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankReconciliationMatch extends Model
{
    protected $fillable = [
        'bank_transaction_id',
        'journal_line_id',
        'bank_reconciliation_id',
        'match_method',
        'confidence_score',
        'matched_by',
        'matched_at',
    ];

    protected $casts = [
        'confidence_score' => 'integer',
        'matched_at' => 'datetime',
    ];

    public function bankTransaction()
    {
        return $this->belongsTo(BankTransaction::class);
    }

    public function journalLine()
    {
        return $this->belongsTo(JournalLine::class);
    }

    public function reconciliation()
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }

    public function matchedBy()
    {
        return $this->belongsTo(User::class, 'matched_by');
    }
}
