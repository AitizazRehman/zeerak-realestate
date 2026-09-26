<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    protected $fillable = ['entry_number', 'entry_date', 'accounting_period_id', 'description', 'status', 'source_type', 'source_id', 'reverses_entry_id', 'created_by', 'posted_by', 'posted_at'];

    protected $casts = ['entry_date' => 'date', 'posted_at' => 'datetime'];

    public function lines() { return $this->hasMany(JournalLine::class); }
    public function period() { return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id'); }
    public function reversal() { return $this->hasOne(self::class, 'reverses_entry_id'); }
}
