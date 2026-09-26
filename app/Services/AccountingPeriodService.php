<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class AccountingPeriodService
{
    /** Call inside the same transaction that posts a journal entry. */
    public function requireOpen($date)
    {
        $date = Carbon::parse($date)->toDateString();
        $match = AccountingPeriod::query()
            ->where('starts_on', '<=', $date)
            ->where('ends_on', '>=', $date)
            ->first();

        if (!$match) {
            throw ValidationException::withMessages([
                'entry_date' => ['The accounting period for this date is unavailable or closed.'],
            ]);
        }

        $year = FiscalYear::whereKey($match->fiscal_year_id)->lockForUpdate()->firstOrFail();
        $period = AccountingPeriod::whereKey($match->id)->lockForUpdate()->firstOrFail();
        if ($period->status !== 'open' || $year->status !== 'open') {
            throw ValidationException::withMessages([
                'entry_date' => ['The accounting period for this date is unavailable or closed.'],
            ]);
        }

        return $period;
    }
}
