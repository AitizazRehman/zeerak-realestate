<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BankAccountController extends Controller
{
    use ChecksBranchAccess;

    private function scopeBranch($query)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;

            if (!$branchId) {
                abort(403, 'Your user account is not assigned to a branch.');
            }

            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    private function normalize(array &$data)
    {
        foreach (['name','bank_name','account_title','account_number','iban','bank_branch','currency','notes'] as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null) {
                continue;
            }

            $data[$field] = trim((string) $data[$field]);

            if ($data[$field] === '') {
                $data[$field] = null;
            }
        }

        if (!empty($data['iban'])) {
            $data['iban'] = strtoupper(str_replace(' ', '', $data['iban']));
        }

        if (!empty($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        } else {
            $data['currency'] = 'PKR';
        }
    }

    private function validateAccount(Request $request, BankAccount $bankAccount = null)
    {
        $id = $bankAccount ? $bankAccount->id : null;

        $data = $request->validate([
            'branch_id' => ['nullable','integer','exists:branches,id'],
            'chart_of_account_id' => [
                'required',
                'integer',
                'exists:chart_of_accounts,id',
                Rule::unique('bank_accounts', 'chart_of_account_id')->ignore($id),
            ],
            'account_type' => ['required','in:bank,cash'],
            'name' => ['required','string','max:150'],
            'bank_name' => ['nullable','string','max:150'],
            'account_title' => ['nullable','string','max:150'],
            'account_number' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('bank_accounts', 'account_number')->ignore($id),
            ],
            'iban' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('bank_accounts', 'iban')->ignore($id),
            ],
            'bank_branch' => ['nullable','string','max:150'],
            'currency' => ['nullable','string','size:3'],
            'is_active' => ['nullable','boolean'],
            'notes' => ['nullable','string','max:2000'],
        ]);

        $this->normalize($data);

        if (!$this->canAccessAllBranches()) {
            $branchId = $request->user()->branch_id;

            if (!$branchId) {
                abort(422, 'Your user account must be assigned to a branch before managing bank accounts.');
            }

            $data['branch_id'] = $branchId;
        } elseif (!empty($data['branch_id'])) {
            $branch = Branch::where('is_active', true)->find($data['branch_id']);

            if (!$branch) {
                throw ValidationException::withMessages([
                    'branch_id' => ['The selected branch is inactive or unavailable.'],
                ]);
            }
        }

        $ledgerAccount = ChartOfAccount::where('is_active', true)->findOrFail($data['chart_of_account_id']);

        if ($ledgerAccount->account_type !== 'asset') {
            throw ValidationException::withMessages([
                'chart_of_account_id' => ['Bank and cash accounts must map to an Asset account in the Chart of Accounts.'],
            ]);
        }

        if ($ledgerAccount->is_control_account || $ledgerAccount->children()->exists()) {
            throw ValidationException::withMessages([
                'chart_of_account_id' => ['Select a posting-level Asset account, not a control/parent account.'],
            ]);
        }

        if ($data['account_type'] === 'bank' && empty($data['bank_name'])) {
            throw ValidationException::withMessages([
                'bank_name' => ['Bank name is required for bank accounts.'],
            ]);
        }

        return $data;
    }

    private function balanceMap($accountIds)
    {
        if (!$accountIds->count()) {
            return collect();
        }

        return DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.status', 'posted')
            ->whereIn('l.chart_of_account_id', $accountIds->all())
            ->groupBy('l.chart_of_account_id')
            ->select('l.chart_of_account_id')
            ->selectRaw('COALESCE(SUM(l.debit - l.credit), 0) as balance')
            ->pluck('balance', 'chart_of_account_id');
    }

    public function index(Request $request)
    {
        $query = $this->scopeBranch(
            BankAccount::with([
                'branch:id,name,code',
                'chartOfAccount:id,code,name,account_type,is_active',
            ])
        );

        if ($request->filled('search')) {
            $search = trim($request->search);

            $query->where(function ($accountQuery) use ($search) {
                $accountQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('bank_name', 'like', "%{$search}%")
                    ->orWhere('account_title', 'like', "%{$search}%")
                    ->orWhere('account_number', 'like', "%{$search}%")
                    ->orWhere('iban', 'like', "%{$search}%");
            });
        }

        if ($request->filled('account_type')) {
            $query->where('account_type', $request->account_type);
        }

        if ($request->filled('branch_id') && $this->canAccessAllBranches()) {
            $query->where('branch_id', (int) $request->branch_id);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $accounts = $query->orderBy('name')->get();
        $balances = $this->balanceMap($accounts->pluck('chart_of_account_id'));

        foreach ($accounts as $account) {
            $account->current_balance = number_format(
                (float) ($balances[$account->chart_of_account_id] ?? 0),
                2,
                '.',
                ''
            );
        }

        return response()->json([
            'data' => $accounts,
            'summary' => [
                'total' => $accounts->count(),
                'active' => $accounts->where('is_active', true)->count(),
                'bank' => $accounts->where('account_type', 'bank')->count(),
                'cash' => $accounts->where('account_type', 'cash')->count(),
                'current_balance' => number_format(
                    (float) $accounts->sum(function ($account) {
                        return (float) $account->current_balance;
                    }),
                    2,
                    '.',
                    ''
                ),
            ],
        ]);
    }

    public function options()
    {
        $accounts = ChartOfAccount::query()
            ->where('account_type', 'asset')
            ->where('is_active', true)
            ->where('is_control_account', false)
            ->whereDoesntHave('children')
            ->whereNotIn('id', BankAccount::pluck('chart_of_account_id'))
            ->orderBy('code')
            ->get(['id','code','name']);

        $branches = collect();

        if ($this->canAccessAllBranches()) {
            $branches = Branch::where('is_active', true)
                ->orderBy('name')
                ->get(['id','name','code']);
        } elseif (auth()->user()->branch_id) {
            $branches = Branch::where('id', auth()->user()->branch_id)
                ->get(['id','name','code']);
        }

        return response()->json([
            'ledger_accounts' => $accounts,
            'branches' => $branches,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateAccount($request);

        $account = DB::transaction(function () use ($data) {
            return BankAccount::create($data);
        });

        return response()->json([
            'message' => 'Bank account created successfully.',
            'account' => $account->load(['branch:id,name,code','chartOfAccount:id,code,name']),
        ], 201);
    }

    public function update(Request $request, BankAccount $bankAccount)
    {
        $this->scopeBranch(BankAccount::query())->findOrFail($bankAccount->id);

        $data = $this->validateAccount($request, $bankAccount);

        $bankAccount->update($data);

        return response()->json([
            'message' => 'Bank account updated successfully.',
            'account' => $bankAccount->fresh()->load(['branch:id,name,code','chartOfAccount:id,code,name']),
        ]);
    }

    public function destroy(BankAccount $bankAccount)
    {
        $bankAccount = $this->scopeBranch(BankAccount::query())->findOrFail($bankAccount->id);

        $hasLedgerHistory = DB::table('journal_lines')
            ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
            ->exists();

        if ($hasLedgerHistory) {
            $bankAccount->update(['is_active' => false]);

            return response()->json([
                'message' => 'This account has accounting history, so it was safely deactivated instead of deleted.',
            ]);
        }

        $bankAccount->delete();

        return response()->json([
            'message' => 'Bank account deleted successfully.',
        ]);
    }
}
