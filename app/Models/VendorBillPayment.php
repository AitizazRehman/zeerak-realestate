<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorBillPayment extends Model
{
    protected $fillable = [
        'vendor_bill_id','cash_bank_account_id','request_key','amount','payment_date',
        'payment_method','reference_number','cheque_number','notes','paid_by',
        'reversed_by','reversed_at','reversal_date','reversal_reason',
    ];

    protected $casts = [
        'vendor_bill_id' => 'integer',
        'cash_bank_account_id' => 'integer',
        'amount' => 'decimal:2',
        'payment_date' => 'date',
        'paid_by' => 'integer',
        'reversed_by' => 'integer',
        'reversed_at' => 'datetime',
        'reversal_date' => 'date',
    ];

    public function bill() { return $this->belongsTo(VendorBill::class, 'vendor_bill_id'); }
    public function cashBankAccount() { return $this->belongsTo(ChartOfAccount::class, 'cash_bank_account_id')->withTrashed(); }
    public function paidBy() { return $this->belongsTo(User::class, 'paid_by'); }
    public function reversedBy() { return $this->belongsTo(User::class, 'reversed_by'); }
    public function journalEntry() { return $this->hasOne(JournalEntry::class, 'source_id')->where('source_type', 'vendor_bill_payment'); }
    public function reversalJournal() { return $this->hasOne(JournalEntry::class, 'source_id')->where('source_type', 'vendor_bill_payment_reverse'); }
    public function allocations() { return $this->hasMany(VendorBillPaymentAllocation::class); }
    public function taxTransactions() { return $this->hasMany(TaxTransaction::class); }
}
