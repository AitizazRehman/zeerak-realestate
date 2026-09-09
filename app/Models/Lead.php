<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use SoftDeletes;
    protected $fillable = ['customer_id','assigned_to','lead_number','name','phone','email','source','status','priority','project_id','budget','next_follow_up','notes'];
    protected $casts = ['budget'=>'decimal:2','next_follow_up'=>'date'];
    public function customer(){ return $this->belongsTo(Customer::class); }
    public function assignee(){ return $this->belongsTo(User::class,'assigned_to'); }
    public function project(){ return $this->belongsTo(Project::class); }
}
