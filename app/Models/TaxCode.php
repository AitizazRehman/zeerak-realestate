<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxCode extends Model
{
    protected $fillable = [
        'code','name','tax_type','rate_percent','chart_of_account_id',
        'effective_from','effective_to','certificate_required','is_active','notes',
    ];

    protected $casts = [
        'rate_percent' => 'decimal:4',
        'chart_of_account_id' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'certificate_required' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function account()
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id')->withTrashed();
    }

    public function transactions()
    {
        return $this->hasMany(TaxTransaction::class);
    }
}
