<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Commission extends Model
{
    protected $fillable = ['booking_id','agent_id','percentage','base_amount','commission_amount','status','approved_date','paid_date','notes'];
    protected $casts = ['percentage'=>'decimal:2','base_amount'=>'decimal:2','commission_amount'=>'decimal:2','approved_date'=>'date','paid_date'=>'date'];
    public function booking(){return $this->belongsTo(Booking::class);}
    public function agent(){return $this->belongsTo(User::class,'agent_id');}
}
