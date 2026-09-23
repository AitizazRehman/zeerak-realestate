<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinancialDocument extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'entity_type',
        'entity_id',
        'document_type',
        'name',
        'file_path',
        'file_size',
        'mime_type',
        'uploaded_by',
        'notes',
    ];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
