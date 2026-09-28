<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorBill extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'vendor_id','branch_id','project_id','bill_number','vendor_invoice_number',
        'bill_date','due_date','description','total_amount','paid_amount','remaining_amount',
        'status','created_by','cancelled_by','cancelled_at','cancellation_date',
        'cancellation_reason','notes',
    ];

    protected $casts = [
        'vendor_id' => 'integer',
        'branch_id' => 'integer',
        'project_id' => 'integer',
        'bill_date' => 'date',
        'due_date' => 'date',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'created_by' => 'integer',
        'cancelled_by' => 'integer',
        'cancelled_at' => 'datetime',
        'cancellation_date' => 'date',
    ];

    public function vendor() { return $this->belongsTo(Vendor::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function project() { return $this->belongsTo(Project::class); }
    public function lines() { return $this->hasMany(VendorBillLine::class); }
    public function payments() { return $this->hasMany(VendorBillPayment::class); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
    public function cancelledBy() { return $this->belongsTo(User::class, 'cancelled_by'); }
    public function journalEntry() { return $this->hasOne(JournalEntry::class, 'source_id')->where('source_type', 'vendor_bill'); }
    public function cancellationJournal() { return $this->hasOne(JournalEntry::class, 'source_id')->where('source_type', 'vendor_bill_cancel'); }
}
