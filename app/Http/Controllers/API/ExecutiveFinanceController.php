<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExecutiveFinanceController extends Controller
{
    use ChecksBranchAccess;

    public function options()
    {
        $branchId = $this->accessibleBranchId(null);

        $branches = $this->canAccessAllBranches()
            ? Branch::where('is_active', true)->orderBy('name')->get(['id','name','code'])
            : Branch::whereKey($branchId)->get(['id','name','code']);

        $projects = Project::where('is_active', true)
            ->when($branchId, function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->orderBy('name')
            ->get(['id','branch_id','name','code']);

        return response()->json([
            'branches' => $branches,
            'projects' => $projects,
        ]);
    }

    public function dashboard(Request $request)
    {
        $filters = $request->validate([
            'from' => ['required','date_format:Y-m-d'],
            'to' => ['required','date_format:Y-m-d','after_or_equal:from'],
            'branch_id' => ['nullable','integer','exists:branches,id'],
            'project_id' => ['nullable','integer','exists:projects,id'],
        ]);

        $branchId = $this->accessibleBranchId($filters['branch_id'] ?? null);
        $projectId = !empty($filters['project_id']) ? (int) $filters['project_id'] : null;

        if ($projectId) {
            $project = Project::findOrFail($projectId);

            if ($branchId && (int) $project->branch_id !== (int) $branchId) {
                abort(422, 'The selected project does not belong to the selected branch.');
            }
        }

        $base = $this->baseLines($filters['to'], $branchId, $projectId);
        $period = (clone $base)->whereDate('e.entry_date', '>=', $filters['from']);

        $profitLoss = $this->profitLoss($period);
        $balanceSheet = $this->balanceSheet($base);
        $controls = $this->controlBalances($base, $branchId, $projectId);
        $projects = $this->projectPerformance($filters['from'], $filters['to'], $branchId, $projectId);
        $trial = $this->trialControl($base);

        $operatingLiquidity = round(
            (float) $controls['cash_bank'] +
            (float) $controls['accounts_receivable'] -
            (float) $controls['accounts_payable'] -
            (float) $controls['customer_advances'],
            2
        );

        $netMargin = abs((float) $profitLoss['revenue']) > 0.009
            ? round(((float) $profitLoss['net_result'] / (float) $profitLoss['revenue']) * 100, 1)
            : null;

        $debtCoverage = (float) $controls['accounts_payable'] > 0.009
            ? round(
                (((float) $controls['cash_bank'] + (float) $controls['accounts_receivable']) /
                (float) $controls['accounts_payable']) * 100,
                1
            )
            : null;

        return response()->json([
            'filters' => [
                'from' => $filters['from'],
                'to' => $filters['to'],
                'branch_id' => $branchId,
                'project_id' => $projectId,
            ],
            'profit_loss' => $profitLoss,
            'balance_sheet' => $balanceSheet,
            'control_balances' => array_merge($controls, [
                'operating_liquidity_position' => $this->money($operatingLiquidity),
            ]),
            'kpis' => [
                'net_margin_percent' => $netMargin,
                'cash_plus_ar_to_ap_percent' => $debtCoverage,
                'trial_balance_difference' => $trial['difference'],
                'trial_balance_balanced' => $trial['balanced'],
                'balance_sheet_difference' => $balanceSheet['difference'],
                'balance_sheet_balanced' => $balanceSheet['balanced'],
            ],
            'project_performance' => $projects,
            'scope_note' => $branchId
                ? 'Branch totals include journal lines carrying a project or customer dimension owned by the selected branch. Untagged company-level journal lines are excluded from branch totals.'
                : 'Company totals include all posted journal lines in the ledger.',
            'definitions' => [
                'operating_liquidity_position' => 'Cash/Bank + Accounts Receivable - Accounts Payable - Customer Advances.',
                'cash_plus_ar_to_ap_percent' => '(Cash/Bank + Accounts Receivable) divided by Accounts Payable.',
                'project_recorded_result' => 'Posted project revenue less posted project cost-of-sales and expenses. It is not a complete economic profitability measure where costs remain unposted or unallocated.',
                'balance_sheet_earnings' => 'Unclosed ledger earnings through the reporting date are included so the accounting equation reflects revenue and expense accounts that have not been formally closed to equity.',
            ],
        ]);
    }

    private function baseLines($to, $branchId = null, $projectId = null)
    {
        $query = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.chart_of_account_id')
            ->leftJoin('projects as p', 'p.id', '=', 'l.project_id')
            ->leftJoin('customers as c', 'c.id', '=', 'l.customer_id')
            ->where('e.status', 'posted')
            ->whereDate('e.entry_date', '<=', $to);

        if ($branchId) {
            $query->where(function ($scope) use ($branchId) {
                $scope->where('p.branch_id', $branchId)
                    ->orWhere('c.branch_id', $branchId);
            });
        }

        if ($projectId) {
            $query->where('l.project_id', $projectId);
        }

        return $query;
    }

    private function profitLoss($period)
    {
        $rows = (clone $period)
            ->whereIn('a.account_type', ['revenue','cost_of_sales','expense'])
            ->select('a.id','a.code','a.name','a.account_type')
            ->selectRaw('COALESCE(SUM(l.debit),0) as debit')
            ->selectRaw('COALESCE(SUM(l.credit),0) as credit')
            ->groupBy('a.id','a.code','a.name','a.account_type')
            ->orderBy('a.code')
            ->get();

        $revenue = 0.0;
        $costOfSales = 0.0;
        $expenses = 0.0;
        $sections = [
            'revenue' => [],
            'cost_of_sales' => [],
            'expense' => [],
        ];

        foreach ($rows as $row) {
            $amount = $row->account_type === 'revenue'
                ? round((float) $row->credit - (float) $row->debit, 2)
                : round((float) $row->debit - (float) $row->credit, 2);

            if ($row->account_type === 'revenue') $revenue += $amount;
            if ($row->account_type === 'cost_of_sales') $costOfSales += $amount;
            if ($row->account_type === 'expense') $expenses += $amount;

            $sections[$row->account_type][] = [
                'id' => $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'amount' => $this->money($amount),
            ];
        }

        $revenue = round($revenue, 2);
        $costOfSales = round($costOfSales, 2);
        $expenses = round($expenses, 2);
        $grossProfit = round($revenue - $costOfSales, 2);
        $netResult = round($grossProfit - $expenses, 2);

        return [
            'revenue' => $this->money($revenue),
            'cost_of_sales' => $this->money($costOfSales),
            'gross_profit' => $this->money($grossProfit),
            'operating_expenses' => $this->money($expenses),
            'net_result' => $this->money($netResult),
            'accounts' => $sections,
        ];
    }

    private function balanceSheet($base)
    {
        $rows = (clone $base)
            ->select('a.id','a.code','a.name','a.account_type')
            ->selectRaw('COALESCE(SUM(l.debit - l.credit),0) as signed_balance')
            ->groupBy('a.id','a.code','a.name','a.account_type')
            ->orderBy('a.code')
            ->get();

        $assets = 0.0;
        $liabilities = 0.0;
        $equity = 0.0;
        $unclosedRevenue = 0.0;
        $unclosedCosts = 0.0;
        $sections = [
            'asset' => [],
            'liability' => [],
            'equity' => [],
        ];

        foreach ($rows as $row) {
            $signed = round((float) $row->signed_balance, 2);

            if ($row->account_type === 'asset') {
                $amount = $signed;
                $assets += $amount;
                $sections['asset'][] = $this->accountRow($row, $amount);
            } elseif ($row->account_type === 'liability') {
                $amount = -$signed;
                $liabilities += $amount;
                $sections['liability'][] = $this->accountRow($row, $amount);
            } elseif ($row->account_type === 'equity') {
                $amount = -$signed;
                $equity += $amount;
                $sections['equity'][] = $this->accountRow($row, $amount);
            } elseif ($row->account_type === 'revenue') {
                $unclosedRevenue += -$signed;
            } elseif (in_array($row->account_type, ['cost_of_sales','expense'], true)) {
                $unclosedCosts += $signed;
            }
        }

        $assets = round($assets, 2);
        $liabilities = round($liabilities, 2);
        $equity = round($equity, 2);
        $earnings = round($unclosedRevenue - $unclosedCosts, 2);
        $liabilitiesAndEquity = round($liabilities + $equity + $earnings, 2);
        $difference = round($assets - $liabilitiesAndEquity, 2);

        return [
            'assets' => $this->money($assets),
            'liabilities' => $this->money($liabilities),
            'equity_before_unclosed_earnings' => $this->money($equity),
            'unclosed_earnings' => $this->money($earnings),
            'liabilities_and_equity' => $this->money($liabilitiesAndEquity),
            'difference' => $this->money($difference),
            'balanced' => abs($difference) <= 0.01,
            'accounts' => $sections,
        ];
    }

    private function controlBalances($base, $branchId, $projectId)
    {
        $codes = ['1200','2100','2300'];

        $rows = (clone $base)
            ->whereIn('a.code', $codes)
            ->select('a.code','a.account_type')
            ->selectRaw('COALESCE(SUM(l.debit - l.credit),0) as signed_balance')
            ->groupBy('a.code','a.account_type')
            ->get()
            ->keyBy('code');

        $receivable = round((float) optional($rows->get('1200'))->signed_balance, 2);
        $payable = round(-(float) optional($rows->get('2100'))->signed_balance, 2);
        $advances = round(-(float) optional($rows->get('2300'))->signed_balance, 2);

        $cash = $this->cashBalance($base, $branchId, $projectId);

        return [
            'cash_bank' => $this->money($cash),
            'accounts_receivable' => $this->money($receivable),
            'accounts_payable' => $this->money($payable),
            'customer_advances' => $this->money($advances),
        ];
    }

    private function cashBalance($base, $branchId, $projectId)
    {
        if ($projectId) {
            $row = (clone $base)
                ->where(function ($account) {
                    $account->where('a.code', '1101')
                        ->orWhere('a.parent_id', function ($subquery) {
                            $subquery->select('id')
                                ->from('chart_of_accounts')
                                ->where('code', '1100')
                                ->limit(1);
                        });
                })
                ->selectRaw('COALESCE(SUM(l.debit - l.credit),0) as balance')
                ->first();

            return round((float) $row->balance, 2);
        }

        $accountIds = BankAccount::where('is_active', true)
            ->when($branchId, function ($query) use ($branchId) {
                $query->where(function ($account) use ($branchId) {
                    $account->where('branch_id', $branchId)
                        ->orWhereNull('branch_id');
                });
            })
            ->pluck('chart_of_account_id');

        if (!$accountIds->count()) {
            $accountIds = DB::table('chart_of_accounts')
                ->where('code', '1101')
                ->pluck('id');
        }

        $row = (clone $base)
            ->whereIn('l.chart_of_account_id', $accountIds)
            ->selectRaw('COALESCE(SUM(l.debit - l.credit),0) as balance')
            ->first();

        return round((float) $row->balance, 2);
    }

    private function projectPerformance($from, $to, $branchId, $projectId = null)
    {
        $query = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.chart_of_account_id')
            ->join('projects as p', 'p.id', '=', 'l.project_id')
            ->where('e.status', 'posted')
            ->whereDate('e.entry_date', '>=', $from)
            ->whereDate('e.entry_date', '<=', $to)
            ->whereIn('a.account_type', ['revenue','cost_of_sales','expense'])
            ->when($branchId, function ($query) use ($branchId) {
                $query->where('p.branch_id', $branchId);
            })
            ->when($projectId, function ($query) use ($projectId) {
                $query->where('p.id', $projectId);
            })
            ->select('p.id','p.name','p.code')
            ->selectRaw("COALESCE(SUM(CASE WHEN a.account_type = 'revenue' THEN l.credit - l.debit ELSE 0 END),0) as revenue")
            ->selectRaw("COALESCE(SUM(CASE WHEN a.account_type IN ('cost_of_sales','expense') THEN l.debit - l.credit ELSE 0 END),0) as costs")
            ->groupBy('p.id','p.name','p.code')
            ->get();

        return $query->map(function ($row) {
            $revenue = round((float) $row->revenue, 2);
            $costs = round((float) $row->costs, 2);
            $result = round($revenue - $costs, 2);
            $margin = abs($revenue) > 0.009 ? round(($result / $revenue) * 100, 1) : null;

            return [
                'project_id' => $row->id,
                'project_name' => $row->name,
                'project_code' => $row->code,
                'revenue' => $this->money($revenue),
                'recorded_costs' => $this->money($costs),
                'recorded_result' => $this->money($result),
                'recorded_margin_percent' => $margin,
            ];
        })->sortByDesc(function ($row) {
            return (float) $row['revenue'];
        })->values();
    }

    private function trialControl($base)
    {
        $row = (clone $base)
            ->selectRaw('COALESCE(SUM(l.debit),0) as debit, COALESCE(SUM(l.credit),0) as credit')
            ->first();

        $difference = round((float) $row->debit - (float) $row->credit, 2);

        return [
            'difference' => $this->money($difference),
            'balanced' => abs($difference) <= 0.01,
        ];
    }

    private function accountRow($row, $amount)
    {
        return [
            'id' => $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'amount' => $this->money($amount),
        ];
    }

    private function accessibleBranchId($requested)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;
            abort_unless($branchId, 403, 'Your user account is not assigned to a branch.');

            if ($requested && (int) $requested !== (int) $branchId) {
                abort(403, 'You cannot view another branch.');
            }

            return (int) $branchId;
        }

        return $requested ? (int) $requested : null;
    }

    private function money($value)
    {
        return number_format((float) $value, 2, '.', '');
    }
}
