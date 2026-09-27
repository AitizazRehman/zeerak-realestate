<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankTransactionController extends Controller
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

    private function accessibleBankAccount($id)
    {
        $query = BankAccount::query()->where('is_active', true);

        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;

            if (!$branchId) {
                abort(403, 'Your user account is not assigned to a branch.');
            }

            $query->where('branch_id', $branchId);
        }

        return $query->findOrFail($id);
    }

    private function validateTransaction(Request $request)
    {
        $data = $request->validate([
            'bank_account_id' => ['required','integer','exists:bank_accounts,id'],
            'transaction_date' => ['required','date_format:Y-m-d'],
            'value_date' => ['nullable','date_format:Y-m-d'],
            'debit' => ['nullable','numeric','min:0'],
            'credit' => ['nullable','numeric','min:0'],
            'running_balance' => ['nullable','numeric'],
            'reference_number' => ['nullable','string','max:150'],
            'cheque_number' => ['nullable','string','max:100'],
            'external_transaction_id' => ['nullable','string','max:191'],
            'description' => ['nullable','string','max:5000'],
            'project_id' => ['nullable','integer','exists:projects,id'],
            'customer_id' => ['nullable','integer','exists:customers,id'],
            'journal_entry_id' => ['nullable','integer','exists:journal_entries,id'],
            'notes' => ['nullable','string','max:5000'],
        ]);

        foreach (['reference_number','cheque_number','external_transaction_id','description','notes'] as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null) {
                continue;
            }

            $data[$field] = trim((string) $data[$field]);

            if ($data[$field] === '') {
                $data[$field] = null;
            }
        }

        $data['debit'] = round((float) ($data['debit'] ?? 0), 2);
        $data['credit'] = round((float) ($data['credit'] ?? 0), 2);

        if (($data['debit'] > 0 && $data['credit'] > 0) ||
            ($data['debit'] <= 0 && $data['credit'] <= 0)) {
            throw ValidationException::withMessages([
                'debit' => ['Enter either a debit (deposit) or a credit (withdrawal), but not both.'],
                'credit' => ['Enter either a debit (deposit) or a credit (withdrawal), but not both.'],
            ]);
        }

        $bankAccount = $this->accessibleBankAccount($data['bank_account_id']);
        $data['branch_id'] = $bankAccount->branch_id;

        $this->validateDimensions($data, $bankAccount);
        $this->validateJournalLink($data, $bankAccount);
        $this->validateExternalId($data);

        $data['source'] = 'manual';
        $data['reconciliation_status'] = 'unmatched';
        $data['match_method'] = null;
        $data['reconciled_at'] = null;
        $data['reconciled_by'] = null;
        $data['transaction_hash'] = $this->transactionHash($data);

        return $data;
    }

    private function validateDimensions(array $data, BankAccount $bankAccount)
    {
        $branchId = $bankAccount->branch_id;

        if (!empty($data['project_id'])) {
            $project = Project::findOrFail($data['project_id']);

            if ($branchId && (int) $project->branch_id !== (int) $branchId) {
                throw ValidationException::withMessages([
                    'project_id' => ['The selected project belongs to a different branch than the bank account.'],
                ]);
            }

            if (!$this->canAccessAllBranches() &&
                (int) $project->branch_id !== (int) auth()->user()->branch_id) {
                abort(403, 'You are not authorized to use this project.');
            }
        }

        if (!empty($data['customer_id'])) {
            $customer = Customer::findOrFail($data['customer_id']);

            if ($branchId && $customer->branch_id &&
                (int) $customer->branch_id !== (int) $branchId) {
                throw ValidationException::withMessages([
                    'customer_id' => ['The selected customer belongs to a different branch than the bank account.'],
                ]);
            }

            if (!$this->canAccessAllBranches() &&
                $customer->branch_id &&
                (int) $customer->branch_id !== (int) auth()->user()->branch_id) {
                abort(403, 'You are not authorized to use this customer.');
            }
        }
    }

    private function validateJournalLink(array $data, BankAccount $bankAccount)
    {
        if (empty($data['journal_entry_id'])) {
            return;
        }

        $entry = JournalEntry::where('status', 'posted')->find($data['journal_entry_id']);

        if (!$entry) {
            throw ValidationException::withMessages([
                'journal_entry_id' => ['Only a posted journal entry can be linked to a bank transaction.'],
            ]);
        }

        $movement = DB::table('journal_lines')
            ->where('journal_entry_id', $entry->id)
            ->where('chart_of_account_id', $bankAccount->chart_of_account_id)
            ->selectRaw('COALESCE(SUM(debit),0) AS debit, COALESCE(SUM(credit),0) AS credit')
            ->first();

        $journalNet = round((float) $movement->debit - (float) $movement->credit, 2);
        $transactionNet = round((float) $data['debit'] - (float) $data['credit'], 2);

        if (abs($journalNet - $transactionNet) > 0.009) {
            throw ValidationException::withMessages([
                'journal_entry_id' => ['The posted journal entry does not contain the same bank-account movement as this transaction.'],
            ]);
        }
    }

    private function validateExternalId(array $data, $ignoreId = null)
    {
        if (empty($data['external_transaction_id'])) {
            return;
        }

        $query = BankTransaction::withTrashed()
            ->where('bank_account_id', $data['bank_account_id'])
            ->where('external_transaction_id', $data['external_transaction_id']);

        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'external_transaction_id' => ['This external transaction ID already exists for the selected bank account.'],
            ]);
        }
    }

    private function transactionHash(array $data)
    {
        return hash('sha256', implode('|', [
            (int) $data['bank_account_id'],
            $data['transaction_date'],
            $data['value_date'] ?? '',
            number_format((float) $data['debit'], 2, '.', ''),
            number_format((float) $data['credit'], 2, '.', ''),
            strtolower((string) ($data['reference_number'] ?? '')),
            strtolower((string) ($data['external_transaction_id'] ?? '')),
            strtolower((string) ($data['description'] ?? '')),
        ]));
    }

    private function applyFilters($query, Request $request)
    {
        if ($request->filled('bank_account_id')) {
            $query->where('bank_account_id', (int) $request->bank_account_id);
        }

        if ($request->filled('branch_id') && $this->canAccessAllBranches()) {
            $query->where('branch_id', (int) $request->branch_id);
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->project_id);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->customer_id);
        }

        if ($request->filled('reconciliation_status')) {
            $query->where('reconciliation_status', $request->reconciliation_status);
        }

        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }

        if ($request->filled('transaction_type')) {
            if ($request->transaction_type === 'deposit') {
                $query->where('debit', '>', 0);
            } elseif ($request->transaction_type === 'withdrawal') {
                $query->where('credit', '>', 0);
            }
        }

        if ($request->filled('from')) {
            $query->whereDate('transaction_date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('transaction_date', '<=', $request->to);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);

            $query->where(function ($q) use ($search) {
                $q->where('reference_number', 'like', "%{$search}%")
                    ->orWhere('cheque_number', 'like', "%{$search}%")
                    ->orWhere('external_transaction_id', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customer) use ($search) {
                        $customer->where('name', 'like', "%{$search}%")
                            ->orWhere('customer_number', 'like', "%{$search}%");
                    });
            });
        }

        return $query;
    }

    public function index(Request $request)
    {
        $request->validate([
            'bank_account_id' => ['nullable','integer'],
            'branch_id' => ['nullable','integer'],
            'project_id' => ['nullable','integer'],
            'customer_id' => ['nullable','integer'],
            'reconciliation_status' => ['nullable','in:unmatched,matched,reconciled'],
            'source' => ['nullable','in:manual,statement_import,system'],
            'transaction_type' => ['nullable','in:deposit,withdrawal'],
            'from' => ['nullable','date_format:Y-m-d'],
            'to' => ['nullable','date_format:Y-m-d','after_or_equal:from'],
            'search' => ['nullable','string','max:200'],
            'per_page' => ['nullable','integer','min:1','max:100'],
        ]);

        $query = $this->scopeBranch(
            BankTransaction::with([
                'bankAccount:id,branch_id,chart_of_account_id,account_type,name,bank_name,account_number,currency',
                'branch:id,name,code',
                'project:id,branch_id,name,code',
                'customer:id,branch_id,name,customer_number',
                'journalEntry:id,entry_number,entry_date,status',
                'reconciledBy:id,name',
                'createdBy:id,name',
            ])
        );

        $this->applyFilters($query, $request);

        $summaryQuery = clone $query;

        $summary = $summaryQuery
            ->selectRaw('COALESCE(SUM(debit),0) AS deposits')
            ->selectRaw('COALESCE(SUM(credit),0) AS withdrawals')
            ->selectRaw("SUM(CASE WHEN reconciliation_status = 'unmatched' THEN 1 ELSE 0 END) AS unmatched")
            ->selectRaw("SUM(CASE WHEN reconciliation_status = 'matched' THEN 1 ELSE 0 END) AS matched")
            ->selectRaw("SUM(CASE WHEN reconciliation_status = 'reconciled' THEN 1 ELSE 0 END) AS reconciled")
            ->first();

        $transactions = $query
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->get('per_page', 25), 1), 100));

        return response()->json([
            'data' => $transactions,
            'summary' => [
                'deposits' => number_format((float) $summary->deposits, 2, '.', ''),
                'withdrawals' => number_format((float) $summary->withdrawals, 2, '.', ''),
                'net_movement' => number_format((float) $summary->deposits - (float) $summary->withdrawals, 2, '.', ''),
                'unmatched' => (int) $summary->unmatched,
                'matched' => (int) $summary->matched,
                'reconciled' => (int) $summary->reconciled,
            ],
        ]);
    }

    public function options(Request $request)
    {
        $branchId = null;

        if ($this->canAccessAllBranches() && $request->filled('branch_id')) {
            $branchId = (int) $request->branch_id;
        } elseif (!$this->canAccessAllBranches()) {
            $branchId = (int) auth()->user()->branch_id;
        }

        $bankAccounts = BankAccount::with('branch:id,name,code')
            ->where('is_active', true)
            ->when($branchId, function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            })
            ->orderBy('name')
            ->get(['id','branch_id','chart_of_account_id','account_type','name','bank_name','account_number','currency']);

        $projects = Project::where('is_active', true)
            ->when($branchId, function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            })
            ->orderBy('name')
            ->get(['id','branch_id','name','code']);

        $customers = Customer::where('is_active', true)
            ->when($branchId, function ($q) use ($branchId) {
                $q->where(function ($customer) use ($branchId) {
                    $customer->where('branch_id', $branchId)
                        ->orWhereHas('bookings.property.project', function ($project) use ($branchId) {
                            $project->where('branch_id', $branchId);
                        });
                });
            })
            ->orderBy('name')
            ->limit(1000)
            ->get(['id','branch_id','name','customer_number']);

        $branches = collect();

        if ($this->canAccessAllBranches()) {
            $branches = Branch::where('is_active', true)
                ->orderBy('name')
                ->get(['id','name','code']);
        }

        return response()->json([
            'bank_accounts' => $bankAccounts,
            'projects' => $projects,
            'customers' => $customers,
            'branches' => $branches,
            'reconciliation_statuses' => ['unmatched','matched','reconciled'],
            'transaction_types' => ['deposit','withdrawal'],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateTransaction($request);
        $data['created_by'] = $request->user()->id;

        $transaction = DB::transaction(function () use ($data) {
            return BankTransaction::create($data);
        });

        return response()->json([
            'message' => 'Bank transaction created successfully.',
            'transaction' => $transaction->load([
                'bankAccount:id,branch_id,chart_of_account_id,account_type,name,bank_name,account_number,currency',
                'branch:id,name,code',
                'project:id,branch_id,name,code',
                'customer:id,branch_id,name,customer_number',
                'journalEntry:id,entry_number,entry_date,status',
            ]),
        ], 201);
    }

    public function update(Request $request, BankTransaction $bankTransaction)
    {
        $bankTransaction = $this->scopeBranch(BankTransaction::query())
            ->findOrFail($bankTransaction->id);

        if ($bankTransaction->isReconciled()) {
            abort(422, 'A reconciled bank transaction cannot be edited. Unreconcile it first.');
        }

        if ($bankTransaction->source !== 'manual') {
            abort(422, 'Imported or system-generated bank transactions cannot be edited from the manual transaction screen.');
        }

        $data = $this->validateTransaction($request);
        $this->validateExternalId($data, $bankTransaction->id);

        $bankTransaction->update($data);

        return response()->json([
            'message' => 'Bank transaction updated successfully.',
            'transaction' => $bankTransaction->fresh()->load([
                'bankAccount:id,branch_id,chart_of_account_id,account_type,name,bank_name,account_number,currency',
                'branch:id,name,code',
                'project:id,branch_id,name,code',
                'customer:id,branch_id,name,customer_number',
                'journalEntry:id,entry_number,entry_date,status',
            ]),
        ]);
    }
}
