<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'receipt_number', 'booking_id', 'installment_id', 'customer_id', 'amount',
        'payment_date', 'payment_method', 'reference_number', 'bank_name',
        'cheque_number', 'status', 'received_by', 'notes', 'reversed_at',
        'reversed_by', 'reversal_reason', 'cash_bank_account_id'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
        'reversed_at' => 'datetime',
    ];

    public function booking() { return $this->belongsTo(Booking::class); }
    public function cashBankAccount() { return $this->belongsTo(ChartOfAccount::class, 'cash_bank_account_id')->withTrashed(); }
    public function journalEntry() { return $this->hasOne(JournalEntry::class, 'source_id')->where('source_type', 'payment'); }
    public function reversalJournal() { return $this->hasOne(JournalEntry::class, 'source_id')->where('source_type', 'payment_reversal'); }
    public function installment() { return $this->belongsTo(Installment::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function receivedBy() { return $this->belongsTo(User::class, 'received_by'); }
    public function reversedBy() { return $this->belongsTo(User::class, 'reversed_by'); }

    public function financialDocuments()
    {
        return $this->hasMany(FinancialDocument::class, 'entity_id')->where('entity_type', 'payment');
    }
}
