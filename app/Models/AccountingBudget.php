<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingBudget extends Model
{
    protected $fillable = [
        'fiscal_year_id','branch_id','project_id','name','status','notes',
        'created_by','approved_by','approved_at',
    ];

    protected $casts = [
        'fiscal_year_id' => 'integer',
        'branch_id' => 'integer',
        'project_id' => 'integer',
        'created_by' => 'integer',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
    ];

    public function fiscalYear() { return $this->belongsTo(FiscalYear::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function project() { return $this->belongsTo(Project::class); }
    public function lines() { return $this->hasMany(AccountingBudgetLine::class); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
    public function approvedBy() { return $this->belongsTo(User::class, 'approved_by'); }
}
