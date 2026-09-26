<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ChartOfAccountController extends Controller
{
    private function normalBalanceForType($type)
    {
        return in_array($type, ['asset','cost_of_sales','expense'], true)
            ? 'debit'
            : 'credit';
    }

    private function validateAccount(Request $request, ChartOfAccount $account = null)
    {
        $accountId = $account ? $account->id : null;

        $data = $request->validate([
            'parent_id' => ['nullable','integer','exists:chart_of_accounts,id'],
            'code' => [
                'required','string','max:30',
                Rule::unique('chart_of_accounts', 'code')->ignore($accountId),
            ],
            'name' => ['required','string','max:150'],
            'account_type' => ['required','in:asset,liability,equity,revenue,cost_of_sales,expense'],
            'is_control_account' => ['nullable','boolean'],
            'allow_manual_posting' => ['nullable','boolean'],
            'is_active' => ['nullable','boolean'],
            'description' => ['nullable','string','max:2000'],
        ]);

        $data['code'] = strtoupper(trim($data['code']));
        $data['name'] = trim($data['name']);
        $data['normal_balance'] = $this->normalBalanceForType($data['account_type']);

        if (!empty($data['parent_id'])) {
            if ($account && (int) $data['parent_id'] === (int) $account->id) {
                abort(422, 'An account cannot be its own parent.');
            }

            $parent = ChartOfAccount::findOrFail($data['parent_id']);

            if ($parent->account_type !== $data['account_type']) {
                abort(422, 'Parent and child accounts must use the same account type.');
            }

            if ($account && $this->isDescendant($parent, $account->id)) {
                abort(422, 'An account cannot be moved below one of its own child accounts.');
            }
        }

        if ($account && $account->children()->exists() && $account->account_type !== $data['account_type']) {
            abort(422, 'Account type cannot be changed while child accounts exist.');
        }

        return $data;
    }

    private function isDescendant(ChartOfAccount $candidate, $accountId)
    {
        $current = $candidate;

        while ($current) {
            if ((int) $current->id === (int) $accountId) {
                return true;
            }

            $current = $current->parent;
        }

        return false;
    }

    public function index(Request $request)
    {
        $query = ChartOfAccount::with('parent:id,code,name')
            ->withCount('children');

        if ($request->filled('search')) {
            $search = trim($request->search);

            $query->where(function ($accountQuery) use ($search) {
                $accountQuery->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('account_type')) {
            $query->where('account_type', $request->account_type);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return response()->json([
            'data' => $query->orderBy('code')->get(),
            'summary' => [
                'total' => ChartOfAccount::count(),
                'active' => ChartOfAccount::where('is_active', true)->count(),
                'control_accounts' => ChartOfAccount::where('is_control_account', true)->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateAccount($request);
        $data['is_system'] = false;

        $account = DB::transaction(function () use ($data) {
            return ChartOfAccount::create($data);
        });

        return response()->json([
            'message' => 'Account created successfully.',
            'account' => $account->load('parent:id,code,name'),
        ], 201);
    }

    public function update(Request $request, ChartOfAccount $chartOfAccount)
    {
        $data = $this->validateAccount($request, $chartOfAccount);

        if ($chartOfAccount->is_system) {
            $data['code'] = $chartOfAccount->code;
            $data['account_type'] = $chartOfAccount->account_type;
            $data['normal_balance'] = $chartOfAccount->normal_balance;
            $data['parent_id'] = $chartOfAccount->parent_id;
        }

        $chartOfAccount->update($data);

        return response()->json([
            'message' => 'Account updated successfully.',
            'account' => $chartOfAccount->fresh()->load('parent:id,code,name'),
        ]);
    }

    public function destroy(ChartOfAccount $chartOfAccount)
    {
        if ($chartOfAccount->is_system) {
            abort(422, 'System accounts cannot be deleted. Deactivate them if they are not currently used.');
        }

        if ($chartOfAccount->children()->exists()) {
            abort(422, 'This account has child accounts. Move or remove the child accounts first.');
        }

        $chartOfAccount->delete();

        return response()->json([
            'message' => 'Account deleted successfully.',
        ]);
    }
}
