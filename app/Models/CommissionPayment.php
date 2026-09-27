<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionPayment extends Model
{
    protected $fillable = ['cash_bank_account_id', 'request_key', 'amount', 'payment_date', 'paid_by', 'reversed_at', 'reversal_date', 'reversed_by', 'reversal_reason'];
    protected $casts = ['amount' => 'decimal:2', 'payment_date' => 'date', 'reversed_at' => 'datetime', 'reversal_date' => 'date'];

    public function commission() { return $this->belongsTo(Commission::class); }
    public function cashBankAccount() { return $this->belongsTo(ChartOfAccount::class, 'cash_bank_account_id')->withTrashed(); }
    public function journalEntry() { return $this->hasOne(JournalEntry::class, 'source_id')->where('source_type', 'commission_payment'); }
    public function reversalJournal() { return $this->hasOne(JournalEntry::class, 'source_id')->where('source_type', 'commission_payment_reverse'); }
}
