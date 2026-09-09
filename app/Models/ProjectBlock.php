<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectBlock extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'name',
        'code',
        'description',
        'total_area',
        'area_unit',
        'status',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function properties()
    {
        return $this->hasMany(Property::class, 'block_id');
    }
}
