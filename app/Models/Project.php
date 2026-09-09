<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id',
        'name',
        'code',
        'project_type',
        'description',
        'location',
        'address',
        'city',
        'latitude',
        'longitude',
        'total_area',
        'area_unit',
        'start_date',
        'expected_completion_date',
        'actual_completion_date',
        'status',
        'construction_progress',
        'budget',
        'actual_cost',
        'cover_image',
        'is_featured',
        'is_active',
    ];

    protected $casts = [
        'start_date' => 'date',
        'expected_completion_date' => 'date',
        'actual_completion_date' => 'date',

        'latitude' => 'float',
        'longitude' => 'float',

        'total_area' => 'decimal:2',
        'budget' => 'decimal:2',
        'actual_cost' => 'decimal:2',

        'is_featured' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function blocks()
    {
        return $this->hasMany(ProjectBlock::class);
    }

    public function properties()
    {
        return $this->hasMany(Property::class);
    }
}