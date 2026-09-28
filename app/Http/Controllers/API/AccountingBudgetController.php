<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\AccountingBudget;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\FiscalYear;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingBudgetController extends Controller
{
    use ChecksBranchAccess;

    public function options()
    {
        $branchId = $this->accessibleBranchId(null);

        $years = FiscalYear::with(['periods' => function ($periods) {
                $periods->orderBy('starts_on');
            }])
            ->orderByDesc('starts_on')
            ->get(['id','name','starts_on','ends_on','status']);

        $branches = $this->canAccessAllBranches()
            ? Branch::where('is_active', true)->orderBy('name')->get(['id','name','code'])
            : Branch::whereKey($branchId)->get(['id','name','code']);

        $projects = Project::where('is_active', true)
            ->when($branchId, function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->orderBy('name')
            ->get(['id','branch_id','name','code']);

        $accounts = ChartOfAccount::whereIn('account_type', ['revenue','cost_of_sales','expense'])
            ->where('is_active', true)
            ->where('allow_manual_posting', true)
            ->doesntHave('children')
            ->orderBy('code')
            ->get(['id','code','name','account_type']);

        return response()->json([
            'fiscal_years' => $years,
            'branches' => $branches,
            'projects' => $projects,
            'accounts' => $accounts,
        ]);
    }

    public function index(Request $request)
    {
        $request->validate([
            'fiscal_year_id' => ['nullable','integer','exists:fiscal_years,id'],
            'branch_id' => ['nullable','integer','exists:branches,id'],
            'project_id' => ['nullable','integer','exists:projects,id'],
            'status' => ['nullable','in:draft,approved'],
            'per_page' => ['nullable','integer','min:1','max:100'],
        ]);

        $query = AccountingBudget::with([
                'fiscalYear:id,name,starts_on,ends_on,status',
                'branch:id,name,code',
                'project:id,name,code',
                'createdBy:id,name',
                'approvedBy:id,name',
            ])
            ->withSum('lines as budget_total', 'amount');

        $branchId = $this->accessibleBranchId($request->branch_id);

        if ($branchId) $query->where('branch_id', $branchId);
        if ($request->filled('fiscal_year_id')) $query->where('fiscal_year_id', (int) $request->fiscal_year_id);
        if ($request->filled('project_id')) $query->where('project_id', (int) $request->project_id);
        if ($request->filled('status')) $query->where('status', $request->status);

        return response()->json(
            $query->orderByDesc('id')
                ->paginate(min(max((int) $request->get('per_page', 25), 1), 100))
        );
    }

    public function show(AccountingBudget $accountingBudget)
    {
        $budget = $this->accessibleBudget($accountingBudget->id);

        return response()->json(
            $budget->load([
                'fiscalYear.periods',
                'branch:id,name,code',
                'project:id,name,code',
                'lines.period:id,fiscal_year_id,name,starts_on,ends_on,status',
                'lines.account:id,code,name,account_type',
                'createdBy:id,name',
                'approvedBy:id,name',
            ])
        );
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['branch_id'] = $this->resolveBranch($data['branch_id'] ?? null);
        $this->validateScope($data['branch_id'], $data['project_id'] ?? null);
        $this->validateLines($data['fiscal_year_id'], $data['lines']);

        $this->assertNoDuplicateName(
            $data['fiscal_year_id'],
            $data['branch_id'],
            $data['project_id'] ?? null,
            $data['name']
        );

        $budget = DB::transaction(function () use ($data, $request) {
            $budget = AccountingBudget::create([
                'fiscal_year_id' => $data['fiscal_year_id'],
                'branch_id' => $data['branch_id'],
                'project_id' => $data['project_id'] ?? null,
                'name' => $data['name'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($data['lines'] as $line) {
                $budget->lines()->create([
                    'accounting_period_id' => $line['accounting_period_id'],
                    'chart_of_account_id' => $line['chart_of_account_id'],
                    'amount' => round((float) $line['amount'], 2),
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            return $budget;
        });

        return response()->json([
            'message' => 'Budget created successfully.',
            'budget' => $budget->load(['fiscalYear','branch','project','lines.period','lines.account']),
        ], 201);
    }

    public function update(Request $request, AccountingBudget $accountingBudget)
    {
        $budget = $this->accessibleBudget($accountingBudget->id);

        if ($budget->status !== 'draft') {
            abort(422, 'Approved budgets are locked and cannot be edited.');
        }

        $data = $this->validated($request);
        $data['branch_id'] = $this->canAccessAllBranches()
            ? $this->resolveBranch($data['branch_id'] ?? $budget->branch_id)
            : $budget->branch_id;

        $this->validateScope($data['branch_id'], $data['project_id'] ?? null);
        $this->validateLines($data['fiscal_year_id'], $data['lines']);

        $this->assertNoDuplicateName(
            $data['fiscal_year_id'],
            $data['branch_id'],
            $data['project_id'] ?? null,
            $data['name'],
            $budget->id
        );

        DB::transaction(function () use ($budget, $data) {
            $budget->update([
                'fiscal_year_id' => $data['fiscal_year_id'],
                'branch_id' => $data['branch_id'],
                'project_id' => $data['project_id'] ?? null,
                'name' => $data['name'],
                'notes' => $data['notes'] ?? null,
            ]);

            $budget->lines()->delete();

            foreach ($data['lines'] as $line) {
                $budget->lines()->create([
                    'accounting_period_id' => $line['accounting_period_id'],
                    'chart_of_account_id' => $line['chart_of_account_id'],
                    'amount' => round((float) $line['amount'], 2),
                    'notes' => $line['notes'] ?? null,
                ]);
            }
        });

        return response()->json([
            'message' => 'Budget updated successfully.',
            'budget' => $budget->fresh()->load(['fiscalYear','branch','project','lines.period','lines.account']),
        ]);
    }

    public function approve(Request $request, AccountingBudget $accountingBudget)
    {
        $budget = $this->accessibleBudget($accountingBudget->id);

        if ($budget->status === 'approved') {
            return response()->json([
                'message' => 'Budget is already approved.',
                'budget' => $budget,
            ]);
        }

        if (!$budget->lines()->exists()) {
            abort(422, 'A budget must contain at least one line before approval.');
        }

        $budget->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return response()->json([
            'message' => 'Budget approved and locked.',
            'budget' => $budget->fresh()->load('approvedBy:id,name'),
        ]);
    }

    public function variance(Request $request, AccountingBudget $accountingBudget)
    {
        $budget = $this->accessibleBudget($accountingBudget->id)->load([
            'fiscalYear.periods',
            'branch:id,name,code',
            'project:id,name,code',
            'lines.period:id,fiscal_year_id,name,starts_on,ends_on,status',
            'lines.account:id,code,name,account_type',
        ]);

        $request->validate([
            'through_period_id' => ['nullable','integer','exists:accounting_periods,id'],
        ]);

        $periods = $budget->fiscalYear->periods->sortBy('starts_on')->values();

        if ($request->filled('through_period_id')) {
            $through = $periods->firstWhere('id', (int) $request->through_period_id);
            if (!$through) abort(422, 'Selected period does not belong to this budget fiscal year.');

            $periods = $periods->filter(function ($period) use ($through) {
                return $period->starts_on <= $through->starts_on;
            })->values();
        }

        if (!$periods->count()) {
            abort(422, 'No accounting periods are available for this budget.');
        }

        $periodIds = $periods->pluck('id');
        $periodEnd = $periods->last()->ends_on;

        $lines = $budget->lines
            ->whereIn('accounting_period_id', $periodIds)
            ->values();

        $actuals = $this->actuals(
            $budget,
            $periods->first()->starts_on,
            $periodEnd,
            $lines->pluck('chart_of_account_id')->unique()->values()
        );

        $rows = $lines->groupBy('chart_of_account_id')->map(function ($accountLines) use ($actuals) {
            $account = $accountLines->first()->account;
            $budgetAmount = round((float) $accountLines->sum('amount'), 2);
            $actual = round((float) ($actuals[$account->id] ?? 0), 2);

            $variance = $account->account_type === 'revenue'
                ? round($actual - $budgetAmount, 2)
                : round($budgetAmount - $actual, 2);

            $variancePercent = abs($budgetAmount) > 0.009
                ? round(($variance / abs($budgetAmount)) * 100, 1)
                : null;

            return [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->account_type,
                'budget' => $this->money($budgetAmount),
                'actual' => $this->money($actual),
                'variance' => $this->money($variance),
                'variance_percent' => $variancePercent,
                'favorable' => $variance >= -0.009,
            ];
        })->sortBy('code')->values();

        $monthly = $periods->map(function ($period) use ($lines, $budget) {
            $periodLines = $lines->where('accounting_period_id', $period->id);
            $budgetRevenue = 0.0;
            $budgetCosts = 0.0;

            foreach ($periodLines as $line) {
                if ($line->account->account_type === 'revenue') $budgetRevenue += (float) $line->amount;
                else $budgetCosts += (float) $line->amount;
            }

            $actual = $this->periodActualSummary($budget, $period);

            return [
                'period_id' => $period->id,
                'period_name' => $period->name,
                'from' => $period->starts_on,
                'to' => $period->ends_on,
                'budget_revenue' => $this->money($budgetRevenue),
                'actual_revenue' => $this->money($actual['revenue']),
                'budget_costs' => $this->money($budgetCosts),
                'actual_costs' => $this->money($actual['costs']),
                'budget_result' => $this->money($budgetRevenue - $budgetCosts),
                'actual_result' => $this->money($actual['revenue'] - $actual['costs']),
            ];
        })->values();

        $summary = [
            'budget_revenue' => 0.0,
            'actual_revenue' => 0.0,
            'budget_costs' => 0.0,
            'actual_costs' => 0.0,
        ];

        foreach ($monthly as $month) {
            foreach (array_keys($summary) as $field) {
                $summary[$field] += (float) $month[$field];
            }
        }

        $summary['budget_result'] = $summary['budget_revenue'] - $summary['budget_costs'];
        $summary['actual_result'] = $summary['actual_revenue'] - $summary['actual_costs'];
        $summary['result_variance'] = $summary['actual_result'] - $summary['budget_result'];

        foreach ($summary as $field => $value) {
            $summary[$field] = $this->money($value);
        }

        return response()->json([
            'budget' => $budget->only(['id','name','status','fiscal_year_id','branch_id','project_id']),
            'through_period_id' => $periods->last()->id,
            'summary' => $summary,
            'accounts' => $rows,
            'periods' => $monthly,
            'variance_definition' => 'Revenue variance = Actual − Budget. Cost/expense variance = Budget − Actual. Positive variance is favorable.',
        ]);
    }

    private function actuals(AccountingBudget $budget, $from, $to, $accountIds)
    {
        if (!$accountIds->count()) return collect();

        $query = $this->actualBase($budget, $from, $to)
            ->whereIn('l.chart_of_account_id', $accountIds)
            ->select('l.chart_of_account_id','a.account_type')
            ->selectRaw("COALESCE(SUM(CASE WHEN a.account_type = 'revenue' THEN l.credit - l.debit ELSE l.debit - l.credit END),0) as actual")
            ->groupBy('l.chart_of_account_id','a.account_type')
            ->get();

        return $query->pluck('actual', 'chart_of_account_id');
    }

    private function periodActualSummary(AccountingBudget $budget, AccountingPeriod $period)
    {
        $rows = $this->actualBase($budget, $period->starts_on, $period->ends_on)
            ->whereIn('a.account_type', ['revenue','cost_of_sales','expense'])
            ->select('a.account_type')
            ->selectRaw('COALESCE(SUM(l.debit),0) as debit')
            ->selectRaw('COALESCE(SUM(l.credit),0) as credit')
            ->groupBy('a.account_type')
            ->get();

        $revenue = 0.0;
        $costs = 0.0;

        foreach ($rows as $row) {
            if ($row->account_type === 'revenue') {
                $revenue += (float) $row->credit - (float) $row->debit;
            } else {
                $costs += (float) $row->debit - (float) $row->credit;
            }
        }

        return [
            'revenue' => round($revenue, 2),
            'costs' => round($costs, 2),
        ];
    }

    private function actualBase(AccountingBudget $budget, $from, $to)
    {
        $query = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.chart_of_account_id')
            ->leftJoin('projects as p', 'p.id', '=', 'l.project_id')
            ->leftJoin('customers as c', 'c.id', '=', 'l.customer_id')
            ->where('e.status', 'posted')
            ->whereDate('e.entry_date', '>=', $from)
            ->whereDate('e.entry_date', '<=', $to);

        if ($budget->project_id) {
            $query->where('l.project_id', $budget->project_id);
        } elseif ($budget->branch_id) {
            $query->where(function ($scope) use ($budget) {
                $scope->where('p.branch_id', $budget->branch_id)
                    ->orWhere('c.branch_id', $budget->branch_id);
            });
        }

        return $query;
    }

    private function validated(Request $request)
    {
        return $request->validate([
            'fiscal_year_id' => ['required','integer','exists:fiscal_years,id'],
            'branch_id' => ['nullable','integer','exists:branches,id'],
            'project_id' => ['nullable','integer','exists:projects,id'],
            'name' => ['required','string','max:150'],
            'notes' => ['nullable','string','max:5000'],
            'lines' => ['required','array','min:1','max:5000'],
            'lines.*.accounting_period_id' => ['required','integer','exists:accounting_periods,id'],
            'lines.*.chart_of_account_id' => ['required','integer','exists:chart_of_accounts,id'],
            'lines.*.amount' => ['required','numeric','min:0'],
            'lines.*.notes' => ['nullable','string','max:500'],
        ]);
    }

    private function validateLines($fiscalYearId, array $lines)
    {
        $seen = [];

        foreach ($lines as $index => $line) {
            $key = $line['accounting_period_id'].'|'.$line['chart_of_account_id'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    'lines.'.$index => ['The same account and accounting period can appear only once.'],
                ]);
            }
            $seen[$key] = true;

            $period = AccountingPeriod::findOrFail($line['accounting_period_id']);
            if ((int) $period->fiscal_year_id !== (int) $fiscalYearId) {
                throw ValidationException::withMessages([
                    'lines.'.$index.'.accounting_period_id' => ['The accounting period must belong to the selected fiscal year.'],
                ]);
            }

            $account = ChartOfAccount::findOrFail($line['chart_of_account_id']);
            if (!$account->is_active ||
                !$account->allow_manual_posting ||
                !in_array($account->account_type, ['revenue','cost_of_sales','expense'], true) ||
                $account->children()->exists()) {
                throw ValidationException::withMessages([
                    'lines.'.$index.'.chart_of_account_id' => ['Choose an active posting-level revenue, cost-of-sales, or expense account.'],
                ]);
            }
        }
    }

    private function validateScope($branchId, $projectId)
    {
        if (!$projectId) return;

        $project = Project::where('is_active', true)->findOrFail($projectId);

        if ((int) $project->branch_id !== (int) $branchId) {
            throw ValidationException::withMessages([
                'project_id' => ['The project must belong to the selected branch.'],
            ]);
        }
    }

    private function assertNoDuplicateName($yearId, $branchId, $projectId, $name, $ignoreId = null)
    {
        $query = AccountingBudget::where('fiscal_year_id', $yearId)
            ->where('branch_id', $branchId)
            ->where('name', trim($name));

        if ($projectId) $query->where('project_id', $projectId);
        else $query->whereNull('project_id');

        if ($ignoreId) $query->where('id', '!=', $ignoreId);

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => ['A budget with this name already exists for the selected fiscal year and scope.'],
            ]);
        }
    }

    private function accessibleBudget($id)
    {
        $query = AccountingBudget::query();
        $branchId = $this->accessibleBranchId(null);

        if ($branchId) $query->where('branch_id', $branchId);

        return $query->findOrFail($id);
    }

    private function accessibleBranchId($requested)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;
            abort_unless($branchId, 403, 'Your user account is not assigned to a branch.');

            if ($requested && (int) $requested !== (int) $branchId) abort(403);

            return (int) $branchId;
        }

        return $requested ? (int) $requested : null;
    }

    private function resolveBranch($branchId)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;
        }

        $branch = Branch::where('is_active', true)->find($branchId);

        if (!$branch) {
            throw ValidationException::withMessages([
                'branch_id' => ['Choose an active branch.'],
            ]);
        }

        return $branch->id;
    }

    private function money($value)
    {
        return number_format((float) $value, 2, '.', '');
    }
}
