<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FixedAssetDepreciation extends Model
{
    protected $fillable = [
        'fixed_asset_id','accounting_period_id','depreciation_date','amount',
        'journal_entry_id','reversal_journal_entry_id','posted_by',
        'reversed_by','reversed_at','reversal_reason',
    ];

    protected $casts = [
        'fixed_asset_id' => 'integer',
        'accounting_period_id' => 'integer',
        'depreciation_date' => 'date',
        'amount' => 'decimal:2',
        'journal_entry_id' => 'integer',
        'reversal_journal_entry_id' => 'integer',
        'posted_by' => 'integer',
        'reversed_by' => 'integer',
        'reversed_at' => 'datetime',
    ];

    public function asset() { return $this->belongsTo(FixedAsset::class, 'fixed_asset_id'); }
    public function period() { return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id'); }
    public function journalEntry() { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
    public function reversalJournal() { return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id'); }
}
