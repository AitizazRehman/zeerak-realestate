<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorBillLine extends Model
{
    protected $fillable = [
        'vendor_bill_id','chart_of_account_id','project_id','description','amount',
    ];

    protected $casts = [
        'vendor_bill_id' => 'integer',
        'chart_of_account_id' => 'integer',
        'project_id' => 'integer',
        'amount' => 'decimal:2',
    ];

    public function bill() { return $this->belongsTo(VendorBill::class, 'vendor_bill_id'); }
    public function account() { return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id')->withTrashed(); }
    public function project() { return $this->belongsTo(Project::class); }
}
