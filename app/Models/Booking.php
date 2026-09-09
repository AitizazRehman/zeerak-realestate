<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use SoftDeletes;
    protected $fillable=['customer_id','property_id','sales_agent_id','booking_number','status','property_price','discount','final_price','paid_amount','remaining_amount','booking_date','notes'];
    protected $casts=['property_price'=>'decimal:2','discount'=>'decimal:2','final_price'=>'decimal:2','paid_amount'=>'decimal:2','remaining_amount'=>'decimal:2','booking_date'=>'date'];
    public function customer(){return $this->belongsTo(Customer::class);}
    public function property(){return $this->belongsTo(Property::class);}
    public function salesAgent(){return $this->belongsTo(User::class,'sales_agent_id');}
    public function installmentPlans(){return $this->hasMany(InstallmentPlan::class);}
    public function installments(){return $this->hasMany(Installment::class);}
    public function payments(){return $this->hasMany(Payment::class);}
    public function commissions(){return $this->hasMany(Commission::class);}
    public function getProjectAttribute(){return $this->property ? $this->property->project : null;}
}
