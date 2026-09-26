<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id','project_id','property_id','created_by','expense_number','category','description',
        'amount','expense_date','payment_method','reference_number','vendor_name','notes',
        'expense_account_id','cash_bank_account_id','reversed_at','reversal_date','reversed_by','reversal_reason'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date',
        'reversed_at' => 'datetime',
        'reversal_date' => 'date',
    ];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function project() { return $this->belongsTo(Project::class); }
    public function property() { return $this->belongsTo(Property::class); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
    public function expenseAccount() { return $this->belongsTo(ChartOfAccount::class, 'expense_account_id')->withTrashed(); }
    public function cashBankAccount() { return $this->belongsTo(ChartOfAccount::class, 'cash_bank_account_id')->withTrashed(); }
    public function journalEntry() { return $this->hasOne(JournalEntry::class, 'source_id')->where('source_type', 'expense'); }
    public function reversalJournal() { return $this->hasOne(JournalEntry::class, 'source_id')->where('source_type', 'expense_reversal'); }
    public function scopeUnreversed($query) { return $query->whereNull('expenses.reversed_at'); }

    public function financialDocuments()
    {
        return $this->hasMany(FinancialDocument::class, 'entity_id')->where('entity_type', 'expense');
    }
}
