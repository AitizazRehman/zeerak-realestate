<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\FiscalYearClosure;
use App\Services\FiscalCloseService;
use Illuminate\Http\Request;

class FiscalCloseController extends Controller
{
    public function readiness(FiscalYear $fiscalYear, FiscalCloseService $service)
    {
        return response()->json($service->readiness($fiscalYear));
    }

    public function periodReadiness(AccountingPeriod $accountingPeriod, FiscalCloseService $service)
    {
        return response()->json($service->periodReadiness($accountingPeriod));
    }

    public function close(Request $request, FiscalYear $fiscalYear, FiscalCloseService $service)
    {
        $closure = $service->close($fiscalYear, $request->user()->id);

        return response()->json([
            'message' => 'Fiscal year closed successfully and P&L balances transferred to Retained Earnings.',
            'closure' => $closure,
        ]);
    }

    public function reopen(Request $request, FiscalYear $fiscalYear, FiscalCloseService $service)
    {
        $data = $request->validate([
            'reason' => ['required','string','min:10','max:2000'],
        ]);

        $closure = $service->reopen(
            $fiscalYear,
            $fiscalYear->ends_on->toDateString(),
            $data['reason'],
            $request->user()->id
        );

        return response()->json([
            'message' => 'Fiscal year reopened and the year-end closing journal reversed.',
            'closure' => $closure,
        ]);
    }

    public function history(FiscalYear $fiscalYear)
    {
        return response()->json([
            'data' => FiscalYearClosure::where('fiscal_year_id', $fiscalYear->id)
                ->with([
                    'closingJournal:id,entry_number,entry_date,status',
                    'reversalJournal:id,entry_number,entry_date,status',
                    'closedBy:id,name',
                    'reopenedBy:id,name',
                ])
                ->orderByDesc('id')
                ->get(),
        ]);
    }
}
