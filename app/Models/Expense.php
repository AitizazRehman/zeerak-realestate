<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id','property_id','created_by','expense_number','category','description',
        'amount','expense_date','payment_method','reference_number','vendor_name','notes'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date',
    ];

    public function project() { return $this->belongsTo(Project::class); }
    public function property() { return $this->belongsTo(Property::class); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
}
