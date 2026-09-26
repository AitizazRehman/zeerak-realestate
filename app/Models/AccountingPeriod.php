<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingPeriod extends Model
{
    protected $fillable = ['fiscal_year_id', 'name', 'starts_on', 'ends_on', 'status'];

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class);
    }
}
