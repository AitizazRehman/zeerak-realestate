<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TreasuryCommitment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id','project_id','bank_account_id','flow_type','category','title',
        'expected_date','amount','probability_percent','status','realized_date',
        'notes','created_by','updated_by',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'project_id' => 'integer',
        'bank_account_id' => 'integer',
        'expected_date' => 'date',
        'realized_date' => 'date',
        'amount' => 'decimal:2',
        'probability_percent' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function project() { return $this->belongsTo(Project::class); }
    public function bankAccount() { return $this->belongsTo(BankAccount::class); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
    public function updatedBy() { return $this->belongsTo(User::class, 'updated_by'); }
}
