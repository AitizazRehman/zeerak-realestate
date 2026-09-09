<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Installment extends Model{protected $fillable=['installment_plan_id','booking_id','installment_number','due_date','amount','paid_amount','remaining_amount','status','paid_date','notes'];protected $casts=['due_date'=>'date','paid_date'=>'date','amount'=>'decimal:2','paid_amount'=>'decimal:2','remaining_amount'=>'decimal:2'];public function plan(){return $this->belongsTo(InstallmentPlan::class,'installment_plan_id');}public function booking(){return $this->belongsTo(Booking::class);}public function payments(){return $this->hasMany(Payment::class);}}
