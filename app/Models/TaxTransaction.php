<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxTransaction extends Model
{
    protected $fillable = [
        'tax_code_id','branch_id','vendor_id','vendor_bill_id','vendor_bill_payment_id',
        'source_type','source_id','transaction_date','tax_period','taxable_amount',
        'tax_rate_percent','tax_amount','net_amount','status','certificate_status',
        'certificate_number','certificate_date','certificate_issued_by',
        'certificate_issued_at','reversed_by','reversed_at','reversal_reason','notes',
    ];

    protected $casts = [
        'tax_code_id' => 'integer',
        'branch_id' => 'integer',
        'vendor_id' => 'integer',
        'vendor_bill_id' => 'integer',
        'vendor_bill_payment_id' => 'integer',
        'source_id' => 'integer',
        'transaction_date' => 'date',
        'taxable_amount' => 'decimal:2',
        'tax_rate_percent' => 'decimal:4',
        'tax_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'certificate_date' => 'date',
        'certificate_issued_by' => 'integer',
        'certificate_issued_at' => 'datetime',
        'reversed_by' => 'integer',
        'reversed_at' => 'datetime',
    ];

    public function taxCode() { return $this->belongsTo(TaxCode::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function vendor() { return $this->belongsTo(Vendor::class); }
    public function vendorBill() { return $this->belongsTo(VendorBill::class); }
    public function vendorBillPayment() { return $this->belongsTo(VendorBillPayment::class); }
    public function certificateIssuedBy() { return $this->belongsTo(User::class, 'certificate_issued_by'); }
    public function reversedBy() { return $this->belongsTo(User::class, 'reversed_by'); }
    public function allocations() { return $this->hasMany(TaxTransactionAllocation::class); }
}
