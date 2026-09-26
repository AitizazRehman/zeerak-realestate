<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FiscalYearController extends Controller
{
    public function index()
    {
        return response()->json(['data' => FiscalYear::with('periods')->orderByDesc('starts_on')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:fiscal_years,name',
            'starts_on' => 'required|date_format:Y-m-d',
            'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on',
        ]);

        $start = Carbon::parse($data['starts_on'])->startOfDay();
        $end = Carbon::parse($data['ends_on'])->startOfDay();

        if (!$start->isSameDay($start->copy()->startOfMonth()) || !$end->isSameDay($end->copy()->endOfMonth())) {
            throw ValidationException::withMessages(['starts_on' => ['Fiscal years must start on the first day of a month and end on the last day of a month.']]);
        }

        if ($start->diffInMonths($end->copy()->addDay()) !== 12) {
            throw ValidationException::withMessages(['ends_on' => ['A fiscal year must cover exactly 12 complete months.']]);
        }

        $year = DB::transaction(function () use ($data, $start, $end) {
            if (FiscalYear::where('starts_on', '<=', $end->toDateString())
                ->where('ends_on', '>=', $start->toDateString())->exists()) {
                throw ValidationException::withMessages(['starts_on' => ['This fiscal year overlaps an existing fiscal year.']]);
            }

            $year = FiscalYear::create($data + ['status' => 'open']);
            for ($month = $start->copy(); $month->lte($end); $month->addMonthNoOverflow()) {
                $year->periods()->create([
                    'name' => $month->format('F Y'),
                    'starts_on' => $month->copy()->startOfMonth()->toDateString(),
                    'ends_on' => $month->copy()->endOfMonth()->toDateString(),
                    'status' => 'open',
                ]);
            }

            return $year->load('periods');
        });

        return response()->json(['message' => 'Fiscal year created.', 'data' => $year], 201);
    }

    public function status(Request $request, FiscalYear $fiscalYear)
    {
        $data = $request->validate(['status' => 'required|in:open,closed']);

        DB::transaction(function () use ($fiscalYear, $data) {
            $year = FiscalYear::whereKey($fiscalYear->id)->lockForUpdate()->firstOrFail();
            if ($data['status'] === 'closed' && $year->periods()->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['status' => ['Close all accounting periods before closing the fiscal year.']]);
            }
            $year->update($data);
        });

        return response()->json(['data' => $fiscalYear->fresh()->load('periods')]);
    }

    public function periodStatus(Request $request, AccountingPeriod $accountingPeriod)
    {
        $data = $request->validate(['status' => 'required|in:open,closed']);

        DB::transaction(function () use ($accountingPeriod, $data) {
            $year = FiscalYear::whereKey($accountingPeriod->fiscal_year_id)->lockForUpdate()->firstOrFail();
            $period = AccountingPeriod::whereKey($accountingPeriod->id)->lockForUpdate()->firstOrFail();
            if ($data['status'] === 'open' && $year->status !== 'open') {
                throw ValidationException::withMessages(['status' => ['Reopen the fiscal year before reopening a period.']]);
            }
            $period->update($data);
        });

        return response()->json(['data' => $accountingPeriod->fresh()]);
    }
}
