<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FixedAssetCategory extends Model
{
    protected $fillable = [
        'name','asset_account_id','accumulated_depreciation_account_id',
        'depreciation_expense_account_id','useful_life_months',
        'residual_value_percent','depreciation_method','is_active','notes',
    ];

    protected $casts = [
        'asset_account_id' => 'integer',
        'accumulated_depreciation_account_id' => 'integer',
        'depreciation_expense_account_id' => 'integer',
        'useful_life_months' => 'integer',
        'residual_value_percent' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function assetAccount() { return $this->belongsTo(ChartOfAccount::class, 'asset_account_id')->withTrashed(); }
    public function accumulatedDepreciationAccount() { return $this->belongsTo(ChartOfAccount::class, 'accumulated_depreciation_account_id')->withTrashed(); }
    public function depreciationExpenseAccount() { return $this->belongsTo(ChartOfAccount::class, 'depreciation_expense_account_id')->withTrashed(); }
    public function assets() { return $this->hasMany(FixedAsset::class); }
}
