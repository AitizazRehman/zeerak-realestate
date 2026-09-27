<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Commission extends Model
{
    protected $fillable = ['booking_id','agent_id','percentage','base_amount','commission_amount','status','approved_date','paid_date','notes'];
    protected $casts = ['percentage'=>'decimal:2','base_amount'=>'decimal:2','commission_amount'=>'decimal:2','approved_date'=>'date','paid_date'=>'date'];
    public function booking(){return $this->belongsTo(Booking::class);}
    public function agent(){return $this->belongsTo(User::class,'agent_id');}
    public function accrualJournal(){return $this->hasOne(JournalEntry::class,'source_id')->where('source_type','commission_approval');}
    public function cancellationJournal(){return $this->hasOne(JournalEntry::class,'source_id')->where('source_type','commission_cancel');}
    public function payments(){return $this->hasMany(CommissionPayment::class)->orderBy('id');}

    public function financialDocuments()
    {
        return $this->hasMany(FinancialDocument::class, 'entity_id')->where('entity_type', 'commission');
    }
}
