<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyStatusHistory extends Model
{
    protected $fillable = [
        'property_id',
        'old_status',
        'new_status',
        'changed_by',
        'notes',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}