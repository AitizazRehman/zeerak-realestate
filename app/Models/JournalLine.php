<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalLine extends Model
{
    protected $fillable = ['chart_of_account_id', 'debit', 'credit', 'description', 'project_id', 'customer_id'];
    protected $casts = ['debit' => 'decimal:2', 'credit' => 'decimal:2'];

    public function entry() { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
    public function account() { return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id'); }
}
