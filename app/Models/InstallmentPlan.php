<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class InstallmentPlan extends Model{use SoftDeletes;protected $fillable=['booking_id','plan_name','frequency','total_amount','down_payment','installment_amount','number_of_installments','start_date','end_date','status','notes'];protected $casts=['total_amount'=>'decimal:2','down_payment'=>'decimal:2','installment_amount'=>'decimal:2','start_date'=>'date','end_date'=>'date'];public function booking(){return $this->belongsTo(Booking::class);}public function installments(){return $this->hasMany(Installment::class);}}
