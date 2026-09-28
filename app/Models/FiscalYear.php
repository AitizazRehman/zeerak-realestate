<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalYear extends Model
{
    protected $fillable = ['name', 'starts_on', 'ends_on', 'status'];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    public function periods()
    {
        return $this->hasMany(AccountingPeriod::class)->orderBy('starts_on');
    }

    public function closure()
    {
        return $this->hasOne(FiscalYearClosure::class)->latest('id');
    }

    public function closures()
    {
        return $this->hasMany(FiscalYearClosure::class)->orderByDesc('id');
    }
}
