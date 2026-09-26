<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Project;
use App\Models\Property;
use App\Models\Expense;
use App\Models\FinancialAudit;
use App\Models\FinancialDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\ExpenseAccountingService;
use App\Services\PaymentAccountingService;

class ExpenseController extends Controller
{
    use ChecksBranchAccess;

    private function applyBranchScope($query)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;
            $query->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                  ->orWhereHas('project', function ($project) use ($branchId) {
                    $project->where('branch_id', $branchId);
                })->orWhere(function ($legacy) use ($branchId) {
                    $legacy->whereNull('project_id')->whereHas('property.project', function ($project) use ($branchId) {
                        $project->where('branch_id', $branchId);
                    });
                });
            });
        }
        return $query;
    }

    private function validateBranchRefs(array $data, $fallbackBranchId = null)
    {
        $branchId = null;

        if (!empty($data['project_id'])) {
            $project = Project::findOrFail($data['project_id']);
            $this->ensureBranchAccess($project->branch_id);
            $branchId = $project->branch_id;
        }

        if (!empty($data['property_id'])) {
            $property = Property::with('project')->findOrFail($data['property_id']);
            $this->ensureBranchAccess($property->project->branch_id);
            $branchId = $property->project->branch_id;

            if (!empty($data['project_id']) && (int) $property->project_id !== (int) $data['project_id']) {
                abort(422, 'Property does not belong to the selected project.');
            }
        }

        if (empty($data['project_id']) && !empty($data['property_id'])) {
            $data['project_id'] = Property::findOrFail($data['property_id'])->project_id;
        }

        if (!$branchId && $fallbackBranchId) {
            $branchId = $fallbackBranchId;
        }

        if (!$branchId && !$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;
            if (!$branchId) {
                abort(422, 'Your user account must be assigned to a branch before recording expenses.');
            }
        }

        $data['branch_id'] = $branchId;

        return $data;
    }

    public function index(Request $request)
    {
        $q = $this->applyBranchScope(Expense::with(['branch:id,name','project:id,name','property:id,property_number','createdBy:id,name','expenseAccount:id,code,name','cashBankAccount:id,code,name','journalEntry:id,source_id,source_type,entry_number','reversalJournal:id,source_id,source_type,entry_number'])->withCount('financialDocuments'));
        $request->validate(['status' => 'nullable|in:active,reversed,all']);
        $status = $request->input('status') ?: 'active';
        if ($status === 'active') $q->unreversed();
        if ($status === 'reversed') $q->whereNotNull('reversed_at');
        foreach (['project_id','property_id','category','payment_method'] as $field) {
            if ($request->filled($field)) $q->where($field, $request->input($field));
        }
        if ($request->filled('from')) $q->whereDate('expense_date', '>=', $request->input('from'));
        if ($request->filled('to')) $q->whereDate('expense_date', '<=', $request->input('to'));
        if ($request->filled('search')) {
            $search = $request->input('search');
            $q->where(function ($w) use ($search) {
                $w->where('expense_number','like',"%{$search}%")
                  ->orWhere('description','like',"%{$search}%")
                  ->orWhere('vendor_name','like',"%{$search}%");
            });
        }
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);
        return response()->json($q->latest('expense_date')->latest('id')->paginate($perPage));
    }

    public function postingAccounts(ExpenseAccountingService $accounting, PaymentAccountingService $cashBank)
    {
        $fields = function ($account) { return $account->only(['id', 'code', 'name']); };
        return response()->json([
            'expense_accounts' => $accounting->accounts()->map($fields),
            'cash_bank_accounts' => $cashBank->accounts()->map($fields),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'expense_account_id'=>'required|integer|exists:chart_of_accounts,id',
            'cash_bank_account_id'=>'required|integer|exists:chart_of_accounts,id',
            'project_id'=>'nullable|exists:projects,id', 'property_id'=>'nullable|exists:properties,id',
            'category'=>'required|string|max:100', 'description'=>'required|string|max:255',
            'amount'=>['required','numeric','min:0.01','regex:/^\d{1,13}(\.\d{1,2})?$/'], 'expense_date'=>'required|date_format:Y-m-d|before_or_equal:today',
            'payment_method'=>'required|in:cash,bank_transfer,cheque,online,other',
            'reference_number'=>'nullable|string|max:100', 'vendor_name'=>'nullable|string|max:255', 'notes'=>'nullable|string',
        ]);
        $data = $this->validateBranchRefs($data);
        $data['created_by'] = $request->user()->id;
        $data['expense_number'] = 'EXP-'.now()->format('Ym').'-'.strtoupper(Str::random(7));
        $expense = DB::transaction(function () use ($data) {
            $expense = Expense::create($data);
            app(ExpenseAccountingService::class)->post($expense, auth()->id());
            FinancialAudit::create([
                'entity_type'=>'expense', 'entity_id'=>$expense->id, 'branch_id'=>$expense->branch_id ?: ($expense->project ? $expense->project->branch_id : optional(optional($expense->property)->project)->branch_id), 'action'=>'created',
                'user_id'=>auth()->id(), 'after_data'=>$expense->fresh()->toArray()
            ]);
            return $expense;
        });
        return response()->json(['message'=>'Expense recorded successfully.','expense'=>$expense->load(['branch','project','property','createdBy','expenseAccount','cashBankAccount','journalEntry','reversalJournal'])], 201);
    }

    public function show(Expense $expense)
    {
        $this->applyBranchScope(Expense::query())->findOrFail($expense->id);
        return response()->json($expense->load(['branch','project','property','createdBy','expenseAccount','cashBankAccount','journalEntry','reversalJournal']));
    }

    public function update(Request $request, Expense $expense)
    {
        $data = $request->validate([
            'expense_account_id'=>'nullable|integer|exists:chart_of_accounts,id',
            'cash_bank_account_id'=>'nullable|integer|exists:chart_of_accounts,id',
            'project_id'=>'nullable|exists:projects,id', 'property_id'=>'nullable|exists:properties,id',
            'category'=>'required|string|max:100', 'description'=>'required|string|max:255',
            'amount'=>['required','numeric','min:0.01','regex:/^\d{1,13}(\.\d{1,2})?$/'], 'expense_date'=>'required|date_format:Y-m-d|before_or_equal:today',
            'payment_method'=>'required|in:cash,bank_transfer,cheque,online,other',
            'reference_number'=>'nullable|string|max:100', 'vendor_name'=>'nullable|string|max:255', 'notes'=>'nullable|string',
        ]);
        $expense = DB::transaction(function () use ($expense, $data) {
            $expense = $this->applyBranchScope(Expense::query())->lockForUpdate()->findOrFail($expense->id);
            if ($expense->reversed_at) abort(422, 'Reversed expenses cannot be edited.');
            $before = $expense->toArray();
            $posted = $expense->journalEntry()->exists() || $expense->expense_account_id || $expense->cash_bank_account_id;
            if ($posted) {
                foreach (['project_id','property_id','category','amount','expense_date','payment_method','expense_account_id','cash_bank_account_id'] as $field) {
                    if (!array_key_exists($field, $data)) continue;
                    $old = $expense->$field;
                    $new = $data[$field];
                    if ($field === 'expense_date') $old = $old->toDateString();
                    if ($field === 'amount') {
                        $old = number_format((float) $old, 2, '.', '');
                        $new = number_format((float) $new, 2, '.', '');
                    }
                    if (substr($field, -3) === '_id') { $old = (int) $old; $new = (int) $new; }
                    if ((string) $old !== (string) $new) abort(422, 'Reverse this expense and record a replacement to change its amount, date, accounts or allocation.');
                }
                $validated = array_intersect_key($data, array_flip(['description','vendor_name','reference_number','notes']));
            } else {
                if (!empty($data['expense_account_id']) || !empty($data['cash_bank_account_id'])) abort(422, 'Historical expenses require a separate accounting migration.');
                unset($data['expense_account_id'], $data['cash_bank_account_id']);
                $validated = $this->validateBranchRefs($data, $expense->branch_id);
            }
            $expense->update($validated);
            FinancialAudit::create([
                'entity_type'=>'expense', 'entity_id'=>$expense->id, 'branch_id'=>$expense->branch_id ?: ($expense->project ? $expense->project->branch_id : optional(optional($expense->property)->project)->branch_id), 'action'=>'updated',
                'user_id'=>auth()->id(), 'before_data'=>$before, 'after_data'=>$expense->fresh()->toArray()
            ]);
            return $expense;
        });
        return response()->json(['message'=>'Expense updated successfully.','expense'=>$expense->fresh()->load(['branch','project','property','createdBy','expenseAccount','cashBankAccount','journalEntry','reversalJournal'])]);
    }

    public function reverse(Request $request, Expense $expense)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:1000',
            'reversal_date' => 'required|date_format:Y-m-d|before_or_equal:today',
        ]);
        $result = DB::transaction(function () use ($expense, $request, $data) {
            $expense = $this->applyBranchScope(Expense::query())->lockForUpdate()->findOrFail($expense->id);
            if ($expense->reversed_at) abort(422, 'This expense has already been reversed.');
            if ($data['reversal_date'] < $expense->expense_date->toDateString()) abort(422, 'The reversal date cannot precede the expense.');
            $before = $expense->toArray();
            app(ExpenseAccountingService::class)->reverse($expense, $request->user()->id, $data['reversal_date'], $data['reason']);
            $expense->update([
                'reversed_at' => now(), 'reversed_by' => $request->user()->id,
                'reversal_date' => $data['reversal_date'], 'reversal_reason' => $data['reason'],
            ]);
            FinancialAudit::create([
                'entity_type' => 'expense', 'entity_id' => $expense->id, 'branch_id' => $expense->branch_id,
                'action' => 'reversed', 'user_id' => $request->user()->id,
                'before_data' => $before, 'after_data' => $expense->fresh()->toArray(), 'reason' => $data['reason'],
            ]);
            return $expense;
        });
        return response()->json(['message' => 'Expense reversed. Original record and documents retained.', 'expense' => $result->load('journalEntry', 'reversalJournal')]);
    }

    public function destroy(Expense $expense)
    {
        DB::transaction(function () use ($expense) {
            $expense = $this->applyBranchScope(Expense::query())->lockForUpdate()->findOrFail($expense->id);
            if ($expense->reversed_at || $expense->expense_account_id || $expense->cash_bank_account_id || $expense->journalEntry()->exists()) {
                abort(422, 'Accounting records cannot be deleted. Use Reverse Expense instead.');
            }
            if (FinancialDocument::where('entity_type', 'expense')->where('entity_id', $expense->id)->exists()) {
                abort(422, 'Expense has supporting documents. Remove the documents before deleting the expense.');
            }
            $before = $expense->toArray();
            FinancialAudit::create([
                'entity_type'=>'expense', 'entity_id'=>$expense->id, 'branch_id'=>$expense->branch_id ?: ($expense->project ? $expense->project->branch_id : optional(optional($expense->property)->project)->branch_id), 'action'=>'deleted',
                'user_id'=>auth()->id(), 'before_data'=>$before,
                'reason'=>'Expense deleted by authorized user'
            ]);
            $expense->delete();
        });
        return response()->json(['message'=>'Expense deleted successfully.']);
    }
}
