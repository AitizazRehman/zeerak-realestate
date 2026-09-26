<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalYear extends Model
{
    protected $fillable = ['name', 'starts_on', 'ends_on', 'status'];

    public function periods()
    {
        return $this->hasMany(AccountingPeriod::class)->orderBy('starts_on');
    }
}
