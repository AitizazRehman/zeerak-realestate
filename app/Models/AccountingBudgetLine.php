<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingBudgetLine extends Model
{
    protected $fillable = [
        'accounting_budget_id','accounting_period_id','chart_of_account_id','amount','notes',
    ];

    protected $casts = [
        'accounting_budget_id' => 'integer',
        'accounting_period_id' => 'integer',
        'chart_of_account_id' => 'integer',
        'amount' => 'decimal:2',
    ];

    public function budget() { return $this->belongsTo(AccountingBudget::class, 'accounting_budget_id'); }
    public function period() { return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id'); }
    public function account() { return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id')->withTrashed(); }
}
