<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FixedAsset extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'fixed_asset_category_id','branch_id','project_id','asset_number','name',
        'serial_number','location','purchase_date','in_service_date','cost',
        'residual_value','useful_life_months','depreciation_method','acquisition_type',
        'acquisition_cash_bank_account_id','acquisition_journal_entry_id','status',
        'disposed_on','disposal_proceeds','disposal_cash_bank_account_id',
        'disposal_journal_entry_id','disposal_reason','notes','created_by','disposed_by',
    ];

    protected $casts = [
        'fixed_asset_category_id' => 'integer',
        'branch_id' => 'integer',
        'project_id' => 'integer',
        'purchase_date' => 'date',
        'in_service_date' => 'date',
        'cost' => 'decimal:2',
        'residual_value' => 'decimal:2',
        'useful_life_months' => 'integer',
        'acquisition_cash_bank_account_id' => 'integer',
        'acquisition_journal_entry_id' => 'integer',
        'disposed_on' => 'date',
        'disposal_proceeds' => 'decimal:2',
        'disposal_cash_bank_account_id' => 'integer',
        'disposal_journal_entry_id' => 'integer',
        'created_by' => 'integer',
        'disposed_by' => 'integer',
    ];

    public function category() { return $this->belongsTo(FixedAssetCategory::class, 'fixed_asset_category_id'); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function project() { return $this->belongsTo(Project::class); }
    public function acquisitionCashBankAccount() { return $this->belongsTo(ChartOfAccount::class, 'acquisition_cash_bank_account_id')->withTrashed(); }
    public function acquisitionJournal() { return $this->belongsTo(JournalEntry::class, 'acquisition_journal_entry_id'); }
    public function disposalCashBankAccount() { return $this->belongsTo(ChartOfAccount::class, 'disposal_cash_bank_account_id')->withTrashed(); }
    public function disposalJournal() { return $this->belongsTo(JournalEntry::class, 'disposal_journal_entry_id'); }
    public function depreciations() { return $this->hasMany(FixedAssetDepreciation::class); }
}
