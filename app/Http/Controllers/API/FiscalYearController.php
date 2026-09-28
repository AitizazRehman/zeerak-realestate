<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Services\FiscalCloseService;
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
            if (FiscalYear::whereDate('starts_on', '<=', $end->toDateString())
                ->whereDate('ends_on', '>=', $start->toDateString())->exists()) {
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

        if ($data['status'] === 'closed') {
            throw ValidationException::withMessages([
                'status' => ['Use the Year-End Close workflow so P&L balances are transferred to Retained Earnings and the close is audited.'],
            ]);
        }

        throw ValidationException::withMessages([
            'status' => ['Use the formal Reopen Year workflow so the closing journal is reversed and the reason is recorded.'],
        ]);
    }

    public function periodStatus(Request $request, AccountingPeriod $accountingPeriod, FiscalCloseService $closeService)
    {
        $data = $request->validate(['status' => 'required|in:open,closed']);

        DB::transaction(function () use ($accountingPeriod, $data, $closeService) {
            $year = FiscalYear::whereKey($accountingPeriod->fiscal_year_id)->lockForUpdate()->firstOrFail();
            $period = AccountingPeriod::whereKey($accountingPeriod->id)->lockForUpdate()->firstOrFail();

            if ($data['status'] === 'open' && $year->status !== 'open') {
                throw ValidationException::withMessages([
                    'status' => ['Reopen the fiscal year through the formal year-end workflow before reopening a period.'],
                ]);
            }

            if ($data['status'] === 'closed') {
                $readiness = $closeService->periodReadiness($period);

                if (!$readiness['ready']) {
                    throw ValidationException::withMessages([
                        'status' => [$readiness['blockers'][0] ?? 'The accounting period is not ready to close.'],
                    ]);
                }
            }

            $period->update($data);
        });

        return response()->json(['data' => $accountingPeriod->fresh()]);
    }
}
