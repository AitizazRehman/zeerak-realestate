<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorBillPaymentAllocation extends Model
{
    protected $fillable = [
        'vendor_bill_payment_id',
        'project_id',
        'amount',
    ];

    protected $casts = [
        'vendor_bill_payment_id' => 'integer',
        'project_id' => 'integer',
        'amount' => 'decimal:2',
    ];

    public function payment()
    {
        return $this->belongsTo(VendorBillPayment::class, 'vendor_bill_payment_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
