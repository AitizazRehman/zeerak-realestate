<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingPeriod extends Model
{
    protected $fillable = ['fiscal_year_id', 'name', 'starts_on', 'ends_on', 'status'];

    protected $casts = [
        'fiscal_year_id' => 'integer',
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class);
    }
}
