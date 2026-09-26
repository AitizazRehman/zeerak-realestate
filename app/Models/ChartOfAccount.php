<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChartOfAccount extends Model
{
    use SoftDeletes;

    protected $table = 'chart_of_accounts';

    protected $fillable = [
        'parent_id',
        'code',
        'name',
        'account_type',
        'normal_balance',
        'is_control_account',
        'allow_manual_posting',
        'is_system',
        'is_active',
        'description',
    ];

    protected $casts = [
        'is_control_account' => 'boolean',
        'allow_manual_posting' => 'boolean',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }
}
