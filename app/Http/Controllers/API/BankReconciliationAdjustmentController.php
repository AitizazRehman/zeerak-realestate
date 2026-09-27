<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationAdjustment;
use App\Models\BankReconciliationMatch;
use App\Models\BankStatementImport;
use App\Models\BankTransaction;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Services\AccountingPeriodService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BankReconciliationAdjustmentController extends Controller
{
    use ChecksBranchAccess;

    private function accessibleTransaction($id)
    {
        $query = BankTransaction::with(['statementImport', 'bankAccount'])
            ->whereNotNull('bank_statement_import_id');

        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;

            if (!$branchId) {
                abort(403, 'Your user account is not assigned to a branch.');
            }

            $query->where('branch_id', $branchId);
        }

        return $query->findOrFail($id);
    }

    private function accessibleAdjustment($id)
    {
        $query = BankReconciliationAdjustment::with([
            'bankTransaction.statementImport',
            'bankTransaction.bankAccount',
            'journalEntry.lines',
        ]);

        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;

            if (!$branchId) {
                abort(403, 'Your user account is not assigned to a branch.');
            }

            $query->whereHas('bankTransaction', function ($transaction) use ($branchId) {
                $transaction->where('branch_id', $branchId);
            });
        }

        return $query->findOrFail($id);
    }

    private function assertEditable(BankTransaction $transaction)
    {
        if (!$transaction->statementImport ||
            $transaction->statementImport->status !== 'imported') {
            abort(422, 'This transaction does not belong to an imported bank statement.');
        }

        $reconciliation = BankReconciliation::where(
            'bank_statement_import_id',
            $transaction->bank_statement_import_id
        )->first();

        if ($reconciliation && $reconciliation->status === 'completed') {
            abort(422, 'This statement belongs to a completed reconciliation. Reopen it before creating or reversing adjustments.');
        }
    }

    public function options(Request $request, BankTransaction $bankTransaction)
    {
        $transaction = $this->accessibleTransaction($bankTransaction->id);
        $this->assertEditable($transaction);

        $bankAccount = BankAccount::findOrFail($transaction->bank_account_id);

        $accounts = ChartOfAccount::query()
            ->withCount('children')
            ->where('is_active', true)
            ->where('allow_manual_posting', true)
            ->where('id', '!=', $bankAccount->chart_of_account_id)
            ->orderBy('code')
            ->get()
            ->filter(function ($account) {
                return (int) $account->children_count === 0;
            })
            ->values()
            ->map(function ($account) {
                return [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'account_type' => $account->account_type,
                    'display' => $account->code.' · '.$account->name,
                ];
            });

        $branchId = $transaction->branch_id;

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

        return response()->json([
            'offset_accounts' => $accounts,
            'projects' => $projects,
            'customers' => $customers,
            'adjustment_types' => [
                ['value' => 'bank_charge', 'text' => 'Bank Charges / Fees', 'suggested_account_type' => 'expense'],
                ['value' => 'bank_interest', 'text' => 'Bank Interest / Profit', 'suggested_account_type' => 'revenue'],
                ['value' => 'withholding_tax', 'text' => 'Withholding Tax / Bank Tax', 'suggested_account_type' => 'asset'],
                ['value' => 'returned_cheque', 'text' => 'Returned Cheque / Reversal', 'suggested_account_type' => 'asset'],
                ['value' => 'direct_deposit', 'text' => 'Direct Deposit / Collection', 'suggested_account_type' => null],
                ['value' => 'custom', 'text' => 'Other Bank Adjustment', 'suggested_account_type' => null],
            ],
        ]);
    }

    public function store(
        Request $request,
        BankTransaction $bankTransaction,
        AccountingPeriodService $periods
    ) {
        $transaction = $this->accessibleTransaction($bankTransaction->id);
        $this->assertEditable($transaction);

        $data = $request->validate([
            'adjustment_type' => ['required','in:bank_charge,bank_interest,withholding_tax,returned_cheque,direct_deposit,custom'],
            'offset_account_id' => ['required','integer','exists:chart_of_accounts,id'],
            'description' => ['required','string','max:500'],
            'project_id' => ['nullable','integer','exists:projects,id'],
            'customer_id' => ['nullable','integer','exists:customers,id'],
        ]);

        $bankAccount = BankAccount::findOrFail($transaction->bank_account_id);
        $offset = ChartOfAccount::withCount('children')->findOrFail($data['offset_account_id']);

        if (!$offset->is_active ||
            !$offset->allow_manual_posting ||
            (int) $offset->children_count > 0) {
            throw ValidationException::withMessages([
                'offset_account_id' => ['Choose an active posting-level account that allows manual posting.'],
            ]);
        }

        if ((int) $offset->id === (int) $bankAccount->chart_of_account_id) {
            throw ValidationException::withMessages([
                'offset_account_id' => ['The adjustment offset account cannot be the same bank account.'],
            ]);
        }

        $this->validateDimensions(
            $data['project_id'] ?? null,
            $data['customer_id'] ?? null,
            $transaction->branch_id
        );

        $amount = round((float) $transaction->amount, 2);

        if ($amount <= 0) {
            abort(422, 'The bank transaction amount must be greater than zero.');
        }

        $adjustment = DB::transaction(function () use (
            $transaction,
            $data,
            $bankAccount,
            $offset,
            $amount,
            $request,
            $periods
        ) {
            $locked = BankTransaction::whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->reconciliation_status !== 'unmatched' ||
                BankReconciliationMatch::where('bank_transaction_id', $locked->id)->exists()) {
                abort(422, 'Only an unmatched bank transaction can create a reconciliation adjustment.');
            }

            if (BankReconciliationAdjustment::where('bank_transaction_id', $locked->id)->exists()) {
                abort(422, 'An adjustment already exists for this bank transaction.');
            }

            $period = $periods->requireOpen($locked->transaction_date);
            $description = Str::limit(trim($data['description']), 500, '');
            $projectId = $data['project_id'] ?? $locked->project_id;
            $customerId = $data['customer_id'] ?? $locked->customer_id;
            $deposit = (float) $locked->debit > 0;

            $entry = JournalEntry::create([
                'entry_number' => 'TMP-'.(string) Str::uuid(),
                'entry_date' => $locked->transaction_date->toDateString(),
                'accounting_period_id' => $period->id,
                'description' => $description,
                'status' => 'posted',
                'source_type' => null,
                'source_id' => null,
                'created_by' => $request->user()->id,
                'posted_by' => $request->user()->id,
                'posted_at' => now(),
            ]);

            $entry->update([
                'entry_number' => 'JE-'.str_pad($entry->id, 8, '0', STR_PAD_LEFT),
            ]);

            $bankLine = $entry->lines()->create([
                'chart_of_account_id' => $bankAccount->chart_of_account_id,
                'debit' => $deposit ? $amount : 0,
                'credit' => $deposit ? 0 : $amount,
                'description' => $description,
                'project_id' => $projectId,
                'customer_id' => $customerId,
            ]);

            $entry->lines()->create([
                'chart_of_account_id' => $offset->id,
                'debit' => $deposit ? 0 : $amount,
                'credit' => $deposit ? $amount : 0,
                'description' => $description,
                'project_id' => $projectId,
                'customer_id' => $customerId,
            ]);

            $reconciliation = BankReconciliation::where(
                'bank_statement_import_id',
                $locked->bank_statement_import_id
            )->first();

            $adjustment = BankReconciliationAdjustment::create([
                'bank_transaction_id' => $locked->id,
                'bank_reconciliation_id' => optional($reconciliation)->id,
                'offset_account_id' => $offset->id,
                'journal_entry_id' => $entry->id,
                'adjustment_type' => $data['adjustment_type'],
                'amount' => $amount,
                'direction' => $deposit ? 'deposit' : 'withdrawal',
                'description' => $description,
                'project_id' => $projectId,
                'customer_id' => $customerId,
                'created_by' => $request->user()->id,
            ]);

            $entry->update([
                'source_type' => 'bank_reconciliation_adjustment',
                'source_id' => $adjustment->id,
            ]);

            BankReconciliationMatch::create([
                'bank_transaction_id' => $locked->id,
                'journal_line_id' => $bankLine->id,
                'bank_reconciliation_id' => optional($reconciliation)->id,
                'match_method' => 'manual',
                'confidence_score' => 100,
                'matched_by' => $request->user()->id,
                'matched_at' => now(),
            ]);

            $locked->update([
                'project_id' => $projectId,
                'customer_id' => $customerId,
                'journal_entry_id' => $entry->id,
                'reconciliation_status' => 'matched',
                'match_method' => 'manual',
                'reconciled_at' => null,
                'reconciled_by' => null,
            ]);

            return $adjustment;
        });

        return response()->json([
            'message' => 'Bank reconciliation adjustment posted and matched successfully.',
            'adjustment' => $adjustment->fresh()->load([
                'offsetAccount:id,code,name,account_type',
                'journalEntry:id,entry_number,entry_date,status,description',
                'createdBy:id,name',
            ]),
        ], 201);
    }

    public function reverse(
        Request $request,
        BankReconciliationAdjustment $bankReconciliationAdjustment,
        AccountingPeriodService $periods
    ) {
        $adjustment = $this->accessibleAdjustment($bankReconciliationAdjustment->id);
        $transaction = $adjustment->bankTransaction;
        $this->assertEditable($transaction);

        $data = $request->validate([
            'entry_date' => ['required','date'],
            'reason' => ['required','string','min:5','max:500'],
        ]);

        $originalDate = $adjustment->journalEntry->entry_date->toDateString();

        if ($data['entry_date'] < $originalDate) {
            throw ValidationException::withMessages([
                'entry_date' => ['The reversal date cannot precede the original adjustment date.'],
            ]);
        }

        $reversal = DB::transaction(function () use (
            $adjustment,
            $transaction,
            $data,
            $request,
            $periods
        ) {
            $lockedAdjustment = BankReconciliationAdjustment::whereKey($adjustment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAdjustment->reversal_journal_entry_id || $lockedAdjustment->reversed_at) {
                abort(422, 'This reconciliation adjustment has already been reversed.');
            }

            $original = JournalEntry::with('lines')
                ->whereKey($lockedAdjustment->journal_entry_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($original->status !== 'posted' || $original->reversal()->exists()) {
                abort(422, 'Only an unreversed posted adjustment can be reversed.');
            }

            $period = $periods->requireOpen($data['entry_date']);

            $reversal = JournalEntry::create([
                'entry_number' => 'TMP-'.(string) Str::uuid(),
                'entry_date' => $data['entry_date'],
                'accounting_period_id' => $period->id,
                'description' => Str::limit('Reversal: '.$data['reason'], 500, ''),
                'status' => 'posted',
                'source_type' => 'bank_reconciliation_adjustment_reversal',
                'source_id' => $lockedAdjustment->id,
                'reverses_entry_id' => $original->id,
                'created_by' => $request->user()->id,
                'posted_by' => $request->user()->id,
                'posted_at' => now(),
            ]);

            $reversal->update([
                'entry_number' => 'JE-'.str_pad($reversal->id, 8, '0', STR_PAD_LEFT),
            ]);

            foreach ($original->lines as $line) {
                $reversal->lines()->create([
                    'chart_of_account_id' => $line->chart_of_account_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'description' => $line->description,
                    'project_id' => $line->project_id,
                    'customer_id' => $line->customer_id,
                ]);
            }

            BankReconciliationMatch::where(
                'bank_transaction_id',
                $transaction->id
            )->delete();

            BankTransaction::whereKey($transaction->id)->update([
                'journal_entry_id' => null,
                'reconciliation_status' => 'unmatched',
                'match_method' => null,
                'reconciled_at' => null,
                'reconciled_by' => null,
            ]);

            $lockedAdjustment->update([
                'reversal_journal_entry_id' => $reversal->id,
                'reversed_by' => $request->user()->id,
                'reversed_at' => now(),
                'reversal_reason' => trim($data['reason']),
            ]);

            return $reversal;
        });

        return response()->json([
            'message' => 'Bank reconciliation adjustment reversed successfully.',
            'reversal' => $reversal->load('lines.account:id,code,name'),
        ]);
    }

    private function validateDimensions($projectId, $customerId, $branchId)
    {
        if ($projectId) {
            $project = Project::findOrFail($projectId);

            if ($branchId && (int) $project->branch_id !== (int) $branchId) {
                throw ValidationException::withMessages([
                    'project_id' => ['The selected project belongs to a different branch than the bank transaction.'],
                ]);
            }
        }

        if ($customerId) {
            $customer = Customer::findOrFail($customerId);

            if ($branchId && $customer->branch_id &&
                (int) $customer->branch_id !== (int) $branchId) {
                throw ValidationException::withMessages([
                    'customer_id' => ['The selected customer belongs to a different branch than the bank transaction.'],
                ]);
            }
        }
    }
}
