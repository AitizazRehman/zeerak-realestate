<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class Payment extends Model{use SoftDeletes;protected $fillable=['receipt_number','booking_id','installment_id','customer_id','amount','payment_date','payment_method','reference_number','bank_name','cheque_number','status','received_by','notes'];protected $casts=['amount'=>'decimal:2','payment_date'=>'date'];public function booking(){return $this->belongsTo(Booking::class);}public function installment(){return $this->belongsTo(Installment::class);}public function customer(){return $this->belongsTo(Customer::class);}public function receivedBy(){return $this->belongsTo(User::class,'received_by');}}
