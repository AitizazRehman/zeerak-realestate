<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Property extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'block_id',
        'property_number',
        'property_type',
        'size',
        'size_unit',
        'price',
        'discount',
        'status',
        'bedrooms',
        'bathrooms',
        'covered_area',
        'covered_area_unit',
        'address',
        'latitude',
        'longitude',
        'description',
        'assigned_agent_id',
        'is_featured',
        'is_published',
    ];

    protected $casts = [
        'size' => 'decimal:2',
        'price' => 'decimal:2',
        'discount' => 'decimal:2',
        'covered_area' => 'decimal:2',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'is_featured' => 'boolean',
        'is_published' => 'boolean',
    ];

    protected $appends = [
        'net_price',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function block()
    {
        return $this->belongsTo(ProjectBlock::class, 'block_id');
    }

    public function assignedAgent()
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function images()
    {
        return $this->hasMany(PropertyImage::class);
    }

    public function documents()
    {
        return $this->hasMany(PropertyDocument::class);
    }

    public function features()
    {
        return $this->hasMany(PropertyFeature::class);
    }

    public function statusHistories()
    {
        return $this->hasMany(PropertyStatusHistory::class);
    }

    public function getNetPriceAttribute()
    {
        return max(
            0,
            (float) $this->price - (float) $this->discount
        );
    }
}