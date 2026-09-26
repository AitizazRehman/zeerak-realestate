<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class AccountingPeriodService
{
    /** Call inside the same transaction that posts a journal entry. */
    public function requireOpen($date)
    {
        $date = Carbon::parse($date)->toDateString();
        $period = AccountingPeriod::with('fiscalYear')
            ->where('starts_on', '<=', $date)
            ->where('ends_on', '>=', $date)
            ->lockForUpdate()
            ->first();

        if (!$period || $period->status !== 'open' || $period->fiscalYear->status !== 'open') {
            throw ValidationException::withMessages([
                'entry_date' => ['The accounting period for this date is unavailable or closed.'],
            ]);
        }

        return $period;
    }
}
