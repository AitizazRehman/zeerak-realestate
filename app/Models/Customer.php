<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'customer_number', 'name', 'cnic', 'phone', 'alternate_phone',
        'email', 'address', 'city', 'source', 'notes', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
