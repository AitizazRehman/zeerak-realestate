<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id','vendor_number','name','contact_person','phone','email','tax_number',
        'address','city','bank_name','account_title','account_number','iban',
        'payment_terms_days','is_active','notes',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'payment_terms_days' => 'integer',
        'is_active' => 'boolean',
    ];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function bills() { return $this->hasMany(VendorBill::class); }
}
