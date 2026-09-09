<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteVisit extends Model
{
    protected $fillable=['customer_id','lead_id','property_id','assigned_to','visit_at','status','feedback','notes'];
    protected $casts=['visit_at'=>'datetime'];
    public function customer(){return $this->belongsTo(Customer::class);}
    public function lead(){return $this->belongsTo(Lead::class);}
    public function property(){return $this->belongsTo(Property::class);}
    public function assignee(){return $this->belongsTo(User::class,'assigned_to');}
}
