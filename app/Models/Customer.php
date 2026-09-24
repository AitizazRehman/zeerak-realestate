<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'customer_number', 'branch_id', 'name', 'cnic', 'phone', 'alternate_phone',
        'email', 'address', 'city', 'source', 'notes', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public static function normalizedPhone($phone)
    {
        return preg_replace('/[\\s\\-().]/', '', trim((string) $phone));
    }

    public function scopeMatchingIdentity($query, $phone = null, $email = null)
    {
        $normalizedPhone = static::normalizedPhone($phone);
        $normalizedEmail = strtolower(trim((string) $email));

        return $query->where(function ($identityQuery) use ($normalizedPhone, $normalizedEmail) {
            $hasPhone = $normalizedPhone !== '';
            $hasEmail = $normalizedEmail !== '';

            if ($hasPhone) {
                $identityQuery->whereRaw(
                    "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '.', '') = ?",
                    [$normalizedPhone]
                );
            }

            if ($hasEmail) {
                if ($hasPhone) {
                    $identityQuery->orWhereRaw('LOWER(TRIM(email)) = ?', [$normalizedEmail]);
                } else {
                    $identityQuery->whereRaw('LOWER(TRIM(email)) = ?', [$normalizedEmail]);
                }
            }

            if (!$hasPhone && !$hasEmail) {
                $identityQuery->whereRaw('1 = 0');
            }
        });
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function leads()
    {
        return $this->hasMany(Lead::class);
    }
}
