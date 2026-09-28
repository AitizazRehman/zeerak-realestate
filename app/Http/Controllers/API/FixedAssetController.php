<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Models\FixedAssetDepreciation;
use App\Models\Project;
use App\Services\FixedAssetAccountingService;
use App\Services\PaymentAccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FixedAssetController extends Controller
{
    use ChecksBranchAccess;

    public function options(PaymentAccountingService $payments)
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

        $categories = FixedAssetCategory::with([
                'assetAccount:id,code,name,account_type,normal_balance',
                'accumulatedDepreciationAccount:id,code,name,account_type,normal_balance',
                'depreciationExpenseAccount:id,code,name,account_type,normal_balance',
            ])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $assetAccounts = ChartOfAccount::where('account_type', 'asset')
            ->where('normal_balance', 'debit')
            ->where('is_active', true)
            ->where('allow_manual_posting', true)
            ->doesntHave('children')
            ->orderBy('code')
            ->get(['id','code','name','account_type','normal_balance']);

        $accumulatedAccounts = ChartOfAccount::where('account_type', 'asset')
            ->where('normal_balance', 'credit')
            ->where('is_active', true)
            ->doesntHave('children')
            ->orderBy('code')
            ->get(['id','code','name','account_type','normal_balance']);

        $expenseAccounts = ChartOfAccount::where('account_type', 'expense')
            ->where('is_active', true)
            ->where('allow_manual_posting', true)
            ->doesntHave('children')
            ->orderBy('code')
            ->get(['id','code','name','account_type','normal_balance']);

        $periods = AccountingPeriod::with('fiscalYear:id,name,status')
            ->where('status', 'open')
            ->orderBy('starts_on')
            ->get(['id','fiscal_year_id','name','starts_on','ends_on','status']);

        return response()->json([
            'branches' => $branches,
            'projects' => $projects,
            'categories' => $categories,
            'asset_accounts' => $assetAccounts,
            'accumulated_depreciation_accounts' => $accumulatedAccounts,
            'depreciation_expense_accounts' => $expenseAccounts,
            'cash_accounts' => $payments->accounts()->map->only(['id','code','name'])->values(),
            'open_periods' => $periods,
        ]);
    }

    public function categories()
    {
        return response()->json(
            FixedAssetCategory::with([
                'assetAccount:id,code,name',
                'accumulatedDepreciationAccount:id,code,name',
                'depreciationExpenseAccount:id,code,name',
            ])
            ->withCount('assets')
            ->orderBy('name')
            ->get()
        );
    }

    public function storeCategory(Request $request)
    {
        $data = $this->categoryData($request);
        $this->validateCategoryAccounts($data);

        $category = FixedAssetCategory::create($data);

        return response()->json([
            'message' => 'Fixed asset category created successfully.',
            'category' => $category->load([
                'assetAccount:id,code,name',
                'accumulatedDepreciationAccount:id,code,name',
                'depreciationExpenseAccount:id,code,name',
            ]),
        ], 201);
    }

    public function updateCategory(Request $request, FixedAssetCategory $fixedAssetCategory)
    {
        $data = $this->categoryData($request);
        $this->validateCategoryAccounts($data);

        if ($fixedAssetCategory->assets()->exists()) {
            $locked = [
                'asset_account_id',
                'accumulated_depreciation_account_id',
                'depreciation_expense_account_id',
            ];

            foreach ($locked as $field) {
                if ((int) $data[$field] !== (int) $fixedAssetCategory->{$field}) {
                    throw ValidationException::withMessages([
                        $field => ['GL account mappings cannot be changed after assets exist in this category.'],
                    ]);
                }
            }
        }

        $fixedAssetCategory->update($data);

        return response()->json([
            'message' => 'Fixed asset category updated successfully.',
            'category' => $fixedAssetCategory->fresh()->load([
                'assetAccount:id,code,name',
                'accumulatedDepreciationAccount:id,code,name',
                'depreciationExpenseAccount:id,code,name',
            ]),
        ]);
    }

    public function index(Request $request, FixedAssetAccountingService $accounting)
    {
        $request->validate([
            'branch_id' => ['nullable','integer','exists:branches,id'],
            'project_id' => ['nullable','integer','exists:projects,id'],
            'category_id' => ['nullable','integer','exists:fixed_asset_categories,id'],
            'status' => ['nullable','in:active,disposed'],
            'search' => ['nullable','string','max:150'],
            'per_page' => ['nullable','integer','min:1','max:100'],
        ]);

        $query = FixedAsset::with([
            'category:id,name,asset_account_id,accumulated_depreciation_account_id,depreciation_expense_account_id',
            'branch:id,name,code',
            'project:id,name,code',
            'acquisitionJournal:id,entry_number,status',
            'disposalJournal:id,entry_number,status',
        ]);

        $branchId = $this->accessibleBranchId($request->branch_id);
        if ($branchId) $query->where('branch_id', $branchId);
        if ($request->filled('project_id')) $query->where('project_id', (int) $request->project_id);
        if ($request->filled('category_id')) $query->where('fixed_asset_category_id', (int) $request->category_id);
        if ($request->filled('status')) $query->where('status', $request->status);

        if ($request->filled('search')) {
            $search = trim($request->search);

            $query->where(function ($q) use ($search) {
                $q->where('asset_number', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%");
            });
        }

        $pager = $query->orderBy('asset_number')
            ->paginate(min(max((int) $request->get('per_page', 25), 1), 100));

        $pager->getCollection()->transform(function ($asset) use ($accounting) {
            $values = $accounting->bookValues($asset);

            $asset->setAttribute('accumulated_depreciation', number_format($values['accumulated_depreciation'], 2, '.', ''));
            $asset->setAttribute('net_book_value', number_format($values['net_book_value'], 2, '.', ''));
            $asset->setAttribute('monthly_depreciation', number_format($values['monthly_depreciation'], 2, '.', ''));
            $asset->setAttribute('remaining_depreciable', number_format($values['remaining_depreciable'], 2, '.', ''));

            return $asset;
        });

        return response()->json($pager);
    }

    public function show(FixedAsset $fixedAsset, FixedAssetAccountingService $accounting)
    {
        $asset = $this->accessibleAsset($fixedAsset->id)->load([
            'category.assetAccount:id,code,name',
            'category.accumulatedDepreciationAccount:id,code,name',
            'category.depreciationExpenseAccount:id,code,name',
            'branch:id,name,code',
            'project:id,name,code',
            'acquisitionCashBankAccount:id,code,name',
            'acquisitionJournal:id,entry_number,status,entry_date',
            'disposalCashBankAccount:id,code,name',
            'disposalJournal:id,entry_number,status,entry_date',
            'depreciations.period:id,name,starts_on,ends_on,status',
            'depreciations.journalEntry:id,entry_number,status,entry_date',
            'depreciations.reversalJournal:id,entry_number,status,entry_date',
        ]);

        return response()->json([
            'asset' => $asset,
            'book_values' => $accounting->bookValues($asset),
        ]);
    }

    public function store(Request $request, FixedAssetAccountingService $accounting)
    {
        $data = $this->assetData($request);
        $data['branch_id'] = $this->resolveBranch($data['branch_id'] ?? null);
        $this->validateAssetScope($data['branch_id'], $data['project_id'] ?? null);

        $category = FixedAssetCategory::where('is_active', true)->findOrFail($data['fixed_asset_category_id']);

        $cost = round((float) $data['cost'], 2);
        $residual = array_key_exists('residual_value', $data) && $data['residual_value'] !== null
            ? round((float) $data['residual_value'], 2)
            : round($cost * ((float) $category->residual_value_percent / 100), 2);

        if ($residual < 0 || $residual >= $cost) {
            throw ValidationException::withMessages([
                'residual_value' => ['Residual value must be zero or greater and less than asset cost.'],
            ]);
        }

        $life = (int) ($data['useful_life_months'] ?? $category->useful_life_months);
        if ($life < 1) {
            abort(422, 'Useful life must be at least one month.');
        }

        if ($data['purchase_date'] > $data['in_service_date']) {
            throw ValidationException::withMessages([
                'in_service_date' => ['In-service date cannot precede purchase date.'],
            ]);
        }

        if ($data['acquisition_type'] === 'cash_bank' && empty($data['acquisition_cash_bank_account_id'])) {
            throw ValidationException::withMessages([
                'acquisition_cash_bank_account_id' => ['Choose a cash/bank account for cash/bank acquisition.'],
            ]);
        }

        $asset = DB::transaction(function () use ($data, $category, $cost, $residual, $life, $request, $accounting) {
            do {
                $number = 'FA-'.now()->format('Y').'-'.strtoupper(Str::random(8));
            } while (FixedAsset::withTrashed()->where('asset_number', $number)->exists());

            $asset = FixedAsset::create([
                'fixed_asset_category_id' => $category->id,
                'branch_id' => $data['branch_id'],
                'project_id' => $data['project_id'] ?? null,
                'asset_number' => $number,
                'name' => $data['name'],
                'serial_number' => $data['serial_number'] ?? null,
                'location' => $data['location'] ?? null,
                'purchase_date' => $data['purchase_date'],
                'in_service_date' => $data['in_service_date'],
                'cost' => $cost,
                'residual_value' => $residual,
                'useful_life_months' => $life,
                'depreciation_method' => 'straight_line',
                'acquisition_type' => $data['acquisition_type'],
                'acquisition_cash_bank_account_id' => $data['acquisition_type'] === 'cash_bank'
                    ? $data['acquisition_cash_bank_account_id']
                    : null,
                'status' => 'active',
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $accounting->postAcquisition($asset, $request->user()->id);

            return $asset;
        });

        return response()->json([
            'message' => 'Fixed asset registered successfully.',
            'asset' => $asset->fresh()->load(['category','branch','project','acquisitionJournal']),
        ], 201);
    }

    public function update(Request $request, FixedAsset $fixedAsset)
    {
        $asset = $this->accessibleAsset($fixedAsset->id);

        if ($asset->status !== 'active') {
            abort(422, 'Disposed assets cannot be edited.');
        }

        $hasHistory = $asset->acquisition_journal_entry_id || $asset->depreciations()->exists();

        $data = $request->validate([
            'name' => ['required','string','max:255'],
            'serial_number' => ['nullable','string','max:150'],
            'location' => ['nullable','string','max:255'],
            'project_id' => ['nullable','integer','exists:projects,id'],
            'notes' => ['nullable','string','max:5000'],
            'fixed_asset_category_id' => ['nullable','integer','exists:fixed_asset_categories,id'],
            'purchase_date' => ['nullable','date'],
            'in_service_date' => ['nullable','date'],
            'cost' => ['nullable','numeric','min:0.01'],
            'residual_value' => ['nullable','numeric','min:0'],
            'useful_life_months' => ['nullable','integer','min:1','max:1200'],
        ]);

        $this->validateAssetScope($asset->branch_id, $data['project_id'] ?? null);

        if ($hasHistory) {
            foreach (['fixed_asset_category_id','purchase_date','in_service_date','cost','residual_value','useful_life_months'] as $field) {
                if (array_key_exists($field, $data)) {
                    unset($data[$field]);
                }
            }
        } else {
            $cost = array_key_exists('cost', $data) ? (float) $data['cost'] : (float) $asset->cost;
            $residual = array_key_exists('residual_value', $data) ? (float) $data['residual_value'] : (float) $asset->residual_value;

            if ($residual >= $cost) {
                throw ValidationException::withMessages([
                    'residual_value' => ['Residual value must be less than asset cost.'],
                ]);
            }
        }

        $asset->update($data);

        return response()->json([
            'message' => 'Fixed asset updated successfully.',
            'asset' => $asset->fresh()->load(['category','branch','project']),
        ]);
    }

    public function postDepreciation(
        Request $request,
        FixedAsset $fixedAsset,
        FixedAssetAccountingService $accounting
    ) {
        $asset = $this->accessibleAsset($fixedAsset->id);
        $data = $request->validate([
            'accounting_period_id' => ['required','integer','exists:accounting_periods,id'],
        ]);

        $period = AccountingPeriod::findOrFail($data['accounting_period_id']);
        $record = $accounting->postDepreciation($asset, $period, $request->user()->id);

        return response()->json([
            'message' => 'Depreciation posted successfully.',
            'depreciation' => $record,
            'book_values' => $accounting->bookValues($asset->fresh()),
        ], 201);
    }

    public function postPeriodDepreciation(Request $request, FixedAssetAccountingService $accounting)
    {
        $data = $request->validate([
            'accounting_period_id' => ['required','integer','exists:accounting_periods,id'],
            'branch_id' => ['nullable','integer','exists:branches,id'],
            'project_id' => ['nullable','integer','exists:projects,id'],
        ]);

        $period = AccountingPeriod::findOrFail($data['accounting_period_id']);
        $branchId = $this->accessibleBranchId($data['branch_id'] ?? null);

        $assets = FixedAsset::where('status', 'active')
            ->when($branchId, function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->when(!empty($data['project_id']), function ($query) use ($data) {
                $query->where('project_id', (int) $data['project_id']);
            })
            ->whereDate('in_service_date', '<=', $period->ends_on)
            ->orderBy('id')
            ->get();

        $posted = [];
        $skipped = [];

        foreach ($assets as $asset) {
            try {
                $record = $accounting->postDepreciation($asset, $period, $request->user()->id);
                $posted[] = [
                    'asset_id' => $asset->id,
                    'asset_number' => $asset->asset_number,
                    'amount' => $record->amount,
                ];
            } catch (ValidationException $e) {
                $skipped[] = [
                    'asset_id' => $asset->id,
                    'asset_number' => $asset->asset_number,
                    'reason' => $e->validator->errors()->first('accounting'),
                ];
            }
        }

        return response()->json([
            'message' => count($posted).' depreciation posting(s) completed.',
            'posted' => $posted,
            'skipped' => $skipped,
        ]);
    }

    public function reverseDepreciation(
        Request $request,
        FixedAssetDepreciation $fixedAssetDepreciation,
        FixedAssetAccountingService $accounting
    ) {
        $asset = $this->accessibleAsset($fixedAssetDepreciation->fixed_asset_id);

        if ((int) $asset->id !== (int) $fixedAssetDepreciation->fixed_asset_id) abort(404);

        $data = $request->validate([
            'reversal_date' => ['required','date','before_or_equal:today'],
            'reason' => ['required','string','min:5','max:1000'],
        ]);

        $entry = $accounting->reverseDepreciation(
            $fixedAssetDepreciation,
            $data['reversal_date'],
            $data['reason'],
            $request->user()->id
        );

        return response()->json([
            'message' => 'Depreciation reversed successfully.',
            'reversal' => $entry,
        ]);
    }

    public function dispose(
        Request $request,
        FixedAsset $fixedAsset,
        FixedAssetAccountingService $accounting
    ) {
        $asset = $this->accessibleAsset($fixedAsset->id);

        $data = $request->validate([
            'disposed_on' => ['required','date','before_or_equal:today'],
            'disposal_proceeds' => ['nullable','numeric','min:0'],
            'disposal_cash_bank_account_id' => ['nullable','integer','exists:chart_of_accounts,id'],
            'disposal_reason' => ['required','string','min:5','max:2000'],
        ]);

        $result = $accounting->dispose($asset, $data, $request->user()->id);

        return response()->json([
            'message' => 'Fixed asset disposed successfully.',
            'result' => $result,
            'asset' => $asset->fresh()->load(['disposalJournal','disposalCashBankAccount']),
        ]);
    }

    private function categoryData(Request $request)
    {
        $routeCategory = $request->route('fixedAssetCategory');
        $categoryId = $routeCategory instanceof FixedAssetCategory
            ? $routeCategory->id
            : (is_numeric($routeCategory) ? (int) $routeCategory : null);

        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('fixed_asset_categories', 'name')->ignore($categoryId),
            ],
            'asset_account_id' => ['required','integer','exists:chart_of_accounts,id'],
            'accumulated_depreciation_account_id' => ['required','integer','exists:chart_of_accounts,id','different:asset_account_id'],
            'depreciation_expense_account_id' => ['required','integer','exists:chart_of_accounts,id'],
            'useful_life_months' => ['required','integer','min:1','max:1200'],
            'residual_value_percent' => ['nullable','numeric','min:0','max:99.99'],
            'depreciation_method' => ['required','in:straight_line'],
            'is_active' => ['nullable','boolean'],
            'notes' => ['nullable','string','max:5000'],
        ]);
    }

    private function assetData(Request $request)
    {
        return $request->validate([
            'fixed_asset_category_id' => ['required','integer','exists:fixed_asset_categories,id'],
            'branch_id' => ['nullable','integer','exists:branches,id'],
            'project_id' => ['nullable','integer','exists:projects,id'],
            'name' => ['required','string','max:255'],
            'serial_number' => ['nullable','string','max:150'],
            'location' => ['nullable','string','max:255'],
            'purchase_date' => ['required','date'],
            'in_service_date' => ['required','date'],
            'cost' => ['required','numeric','min:0.01'],
            'residual_value' => ['nullable','numeric','min:0'],
            'useful_life_months' => ['nullable','integer','min:1','max:1200'],
            'acquisition_type' => ['required','in:existing_gl,cash_bank'],
            'acquisition_cash_bank_account_id' => ['nullable','integer','exists:chart_of_accounts,id'],
            'notes' => ['nullable','string','max:5000'],
        ]);
    }

    private function validateCategoryAccounts(array $data)
    {
        $asset = ChartOfAccount::findOrFail($data['asset_account_id']);
        $accumulated = ChartOfAccount::findOrFail($data['accumulated_depreciation_account_id']);
        $expense = ChartOfAccount::findOrFail($data['depreciation_expense_account_id']);

        if (!$asset->is_active || $asset->account_type !== 'asset' || $asset->normal_balance !== 'debit' || !$asset->allow_manual_posting || $asset->children()->exists()) {
            throw ValidationException::withMessages([
                'asset_account_id' => ['Choose an active posting-level debit-normal asset account.'],
            ]);
        }

        if (!$accumulated->is_active || $accumulated->account_type !== 'asset' || $accumulated->normal_balance !== 'credit' || $accumulated->children()->exists()) {
            throw ValidationException::withMessages([
                'accumulated_depreciation_account_id' => ['Choose an active posting-level credit-normal asset account.'],
            ]);
        }

        if (!$expense->is_active || $expense->account_type !== 'expense' || !$expense->allow_manual_posting || $expense->children()->exists()) {
            throw ValidationException::withMessages([
                'depreciation_expense_account_id' => ['Choose an active posting-level expense account.'],
            ]);
        }
    }

    private function validateAssetScope($branchId, $projectId)
    {
        if (!$projectId) return;

        $project = Project::where('is_active', true)->findOrFail($projectId);

        if ((int) $project->branch_id !== (int) $branchId) {
            throw ValidationException::withMessages([
                'project_id' => ['The project must belong to the selected branch.'],
            ]);
        }
    }

    private function accessibleAsset($id)
    {
        $query = FixedAsset::query();
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
}
