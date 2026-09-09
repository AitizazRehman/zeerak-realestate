<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialAudit extends Model
{
    protected $fillable = ['entity_type','entity_id','action','user_id','before_data','after_data','reason'];
    protected $casts = ['before_data'=>'array','after_data'=>'array'];
    public function user(){return $this->belongsTo(User::class);}
}
