<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalYearClosure extends Model
{
    protected $fillable = [
        'fiscal_year_id','closing_journal_entry_id','reversal_journal_entry_id',
        'net_result','status','checklist_snapshot','closed_by','closed_at',
        'reopened_by','reopened_at','reopen_reason',
    ];

    protected $casts = [
        'fiscal_year_id' => 'integer',
        'closing_journal_entry_id' => 'integer',
        'reversal_journal_entry_id' => 'integer',
        'net_result' => 'decimal:2',
        'checklist_snapshot' => 'array',
        'closed_by' => 'integer',
        'closed_at' => 'datetime',
        'reopened_by' => 'integer',
        'reopened_at' => 'datetime',
    ];

    public function fiscalYear() { return $this->belongsTo(FiscalYear::class); }
    public function closingJournal() { return $this->belongsTo(JournalEntry::class, 'closing_journal_entry_id'); }
    public function reversalJournal() { return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id'); }
    public function closedBy() { return $this->belongsTo(User::class, 'closed_by'); }
    public function reopenedBy() { return $this->belongsTo(User::class, 'reopened_by'); }
}
