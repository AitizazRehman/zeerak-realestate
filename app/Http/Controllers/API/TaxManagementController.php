<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Project;
use App\Models\TaxCode;
use App\Models\TaxTransaction;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TaxManagementController extends Controller
{
    use ChecksBranchAccess;

    public function options()
    {
        $branchId = $this->accessibleBranchId(null);

        $branches = $this->canAccessAllBranches()
            ? Branch::where('is_active', true)->orderBy('name')->get(['id','name','code'])
            : Branch::whereKey($branchId)->get(['id','name','code']);

        $vendors = Vendor::where('is_active', true)
            ->when($branchId, function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->orderBy('name')
            ->get(['id','branch_id','vendor_number','name','tax_number']);

        $projects = Project::where('is_active', true)
            ->when($branchId, function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->orderBy('name')
            ->get(['id','branch_id','name','code']);

        $accounts = ChartOfAccount::whereIn('account_type', ['asset','liability'])
            ->where('is_active', true)
            ->doesntHave('children')
            ->orderBy('code')
            ->get(['id','code','name','account_type','normal_balance']);

        return response()->json([
            'branches' => $branches,
            'vendors' => $vendors,
            'projects' => $projects,
            'accounts' => $accounts,
            'tax_types' => [
                ['value'=>'withholding_payable','text'=>'Withholding Tax Payable'],
                ['value'=>'input_tax_receivable','text'=>'Input Tax Receivable'],
                ['value'=>'output_tax_payable','text'=>'Output Tax Payable'],
            ],
        ]);
    }

    public function codes(Request $request)
    {
        $request->validate([
            'tax_type' => ['nullable','in:withholding_payable,input_tax_receivable,output_tax_payable'],
            'active' => ['nullable','boolean'],
        ]);

        $query = TaxCode::with('account:id,code,name,account_type,normal_balance')
            ->withCount('transactions');

        if ($request->filled('tax_type')) $query->where('tax_type', $request->tax_type);
        if ($request->has('active')) $query->where('is_active', $request->boolean('active'));

        return response()->json([
            'data' => $query->orderBy('code')->get(),
        ]);
    }

    public function storeCode(Request $request)
    {
        $data = $this->codeData($request);
        $this->validateAccountMapping($data['tax_type'], $data['chart_of_account_id']);

        $code = TaxCode::create($data);

        return response()->json([
            'message' => 'Tax code created successfully.',
            'data' => $code->load('account:id,code,name,account_type,normal_balance'),
        ], 201);
    }

    public function updateCode(Request $request, TaxCode $taxCode)
    {
        $data = $this->codeData($request, $taxCode);
        $this->validateAccountMapping($data['tax_type'], $data['chart_of_account_id']);

        if ($taxCode->transactions()->exists() && $data['tax_type'] !== $taxCode->tax_type) {
            throw ValidationException::withMessages([
                'tax_type' => ['Tax type cannot be changed after transactions exist. Create a new tax code instead.'],
            ]);
        }

        $taxCode->update($data);

        return response()->json([
            'message' => 'Tax code updated successfully.',
            'data' => $taxCode->fresh()->load('account:id,code,name,account_type,normal_balance'),
        ]);
    }

    public function register(Request $request)
    {
        $filters = $request->validate([
            'from' => ['required','date_format:Y-m-d'],
            'to' => ['required','date_format:Y-m-d','after_or_equal:from'],
            'branch_id' => ['nullable','integer','exists:branches,id'],
            'vendor_id' => ['nullable','integer','exists:vendors,id'],
            'project_id' => ['nullable','integer','exists:projects,id'],
            'tax_code_id' => ['nullable','integer','exists:tax_codes,id'],
            'tax_type' => ['nullable','in:withholding_payable,input_tax_receivable,output_tax_payable'],
            'status' => ['nullable','in:active,reversed'],
            'certificate_status' => ['nullable','in:not_required,pending,issued'],
            'per_page' => ['nullable','integer','min:1','max:100'],
        ]);

        $branchId = $this->accessibleBranchId($filters['branch_id'] ?? null);
        $projectId = !empty($filters['project_id']) ? (int) $filters['project_id'] : null;

        if ($projectId) {
            $project = Project::findOrFail($projectId);
            if ($branchId && (int) $project->branch_id !== (int) $branchId) {
                abort(422, 'The selected project does not belong to the selected branch.');
            }
        }

        $base = TaxTransaction::with([
                'taxCode:id,code,name',
                'account:id,code,name,account_type,normal_balance',
                'branch:id,name,code',
                'vendor:id,vendor_number,name,tax_number',
                'vendorBill:id,bill_number,vendor_invoice_number',
                'vendorBillPayment:id,amount,payment_date,reference_number',
                'allocations.project:id,name,code',
                'certificateIssuedBy:id,name',
                'reversedBy:id,name',
            ])
            ->whereDate('transaction_date', '>=', $filters['from'])
            ->whereDate('transaction_date', '<=', $filters['to']);

        if ($branchId) $base->where('branch_id', $branchId);
        if (!empty($filters['vendor_id'])) $base->where('vendor_id', (int) $filters['vendor_id']);
        if (!empty($filters['tax_code_id'])) $base->where('tax_code_id', (int) $filters['tax_code_id']);
        if (!empty($filters['tax_type'])) $base->where('tax_type', $filters['tax_type']);
        if (!empty($filters['status'])) $base->where('status', $filters['status']);
        if (!empty($filters['certificate_status'])) $base->where('certificate_status', $filters['certificate_status']);
        if ($projectId) {
            $base->whereHas('allocations', function ($query) use ($projectId) {
                $query->where('project_id', $projectId);
            });
        }

        $summaryRows = (clone $base)->get();
        $activeRows = $summaryRows->where('status', 'active');

        $summary = [
            'transactions' => $summaryRows->count(),
            'active_transactions' => $activeRows->count(),
            'reversed_transactions' => $summaryRows->where('status', 'reversed')->count(),
            'taxable_amount' => $this->money($activeRows->sum('taxable_amount')),
            'tax_amount' => $this->money($activeRows->sum('tax_amount')),
            'net_amount' => $this->money($activeRows->sum('net_amount')),
            'pending_certificates' => $activeRows->where('certificate_status', 'pending')->count(),
            'issued_certificates' => $activeRows->where('certificate_status', 'issued')->count(),
        ];

        $byCode = $activeRows->groupBy('tax_code_id')->map(function ($rows) {
            $first = $rows->first();

            return [
                'tax_code_id' => $first->tax_code_id,
                'code' => optional($first->taxCode)->code,
                'name' => optional($first->taxCode)->name,
                'tax_type' => $first->tax_type,
                'taxable_amount' => $this->money($rows->sum('taxable_amount')),
                'tax_amount' => $this->money($rows->sum('tax_amount')),
                'transaction_count' => $rows->count(),
            ];
        })->sortBy('code')->values();

        $byVendor = $activeRows->whereNotNull('vendor_id')
            ->groupBy('vendor_id')
            ->map(function ($rows) {
                $first = $rows->first();

                return [
                    'vendor_id' => $first->vendor_id,
                    'vendor_number' => optional($first->vendor)->vendor_number,
                    'vendor_name' => optional($first->vendor)->name,
                    'tax_number' => optional($first->vendor)->tax_number,
                    'taxable_amount' => $this->money($rows->sum('taxable_amount')),
                    'tax_amount' => $this->money($rows->sum('tax_amount')),
                    'transaction_count' => $rows->count(),
                    'pending_certificates' => $rows->where('certificate_status', 'pending')->count(),
                ];
            })
            ->sortByDesc(function ($row) {
                return (float) $row['tax_amount'];
            })
            ->values();

        $ledger = $this->ledgerPositions(
            $filters['to'],
            $branchId,
            $filters['tax_code_id'] ?? null,
            $filters['tax_type'] ?? null
        );

        $pager = (clone $base)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->get('per_page', 25), 1), 100));

        return response()->json([
            'filters' => array_merge($filters, ['branch_id' => $branchId]),
            'summary' => $summary,
            'by_code' => $byCode,
            'by_vendor' => $byVendor,
            'ledger_positions' => $ledger,
            'transactions' => $pager,
            'definitions' => [
                'withholding_payable' => 'Tax deducted from a vendor settlement and credited to the mapped tax liability account.',
                'ledger_difference' => 'Mapped GL balance less cumulative active tax-register amount through the report end date. Manual settlements or other GL entries can create a difference.',
            ],
        ]);
    }

    public function issueCertificate(Request $request, TaxTransaction $taxTransaction)
    {
        $this->ensureTransactionAccess($taxTransaction);

        if ($taxTransaction->status !== 'active') {
            abort(422, 'A reversed tax transaction cannot receive a withholding certificate.');
        }

        if ($taxTransaction->tax_type !== 'withholding_payable') {
            abort(422, 'Certificates are only available for withholding tax transactions.');
        }

        $taxTransaction->loadMissing('taxCode');

        if (!$taxTransaction->taxCode || !$taxTransaction->taxCode->certificate_required) {
            abort(422, 'This tax code does not require a withholding certificate.');
        }

        if ($taxTransaction->certificate_status === 'issued') {
            return response()->json([
                'message' => 'Certificate has already been issued.',
                'data' => $taxTransaction,
            ]);
        }

        $data = $request->validate([
            'certificate_date' => ['nullable','date','before_or_equal:today'],
            'certificate_number' => ['nullable','string','max:100','unique:tax_transactions,certificate_number'],
        ]);

        $number = trim((string) ($data['certificate_number'] ?? ''));

        if ($number === '') {
            $number = 'WHT-'.$taxTransaction->transaction_date->format('Ym').'-'.str_pad($taxTransaction->id, 8, '0', STR_PAD_LEFT);
        }

        $taxTransaction->update([
            'certificate_status' => 'issued',
            'certificate_number' => $number,
            'certificate_date' => $data['certificate_date'] ?? now()->toDateString(),
            'certificate_issued_by' => $request->user()->id,
            'certificate_issued_at' => now(),
        ]);

        return response()->json([
            'message' => 'Withholding certificate issued successfully.',
            'data' => $taxTransaction->fresh()->load([
                'taxCode:id,code,name',
                'vendor:id,vendor_number,name,tax_number',
                'vendorBill:id,bill_number,vendor_invoice_number',
                'vendorBillPayment:id,amount,payment_date,reference_number',
                'certificateIssuedBy:id,name',
            ]),
        ]);
    }

    private function codeData(Request $request, TaxCode $taxCode = null)
    {
        $id = $taxCode ? $taxCode->id : null;

        return $request->validate([
            'code' => ['required','string','max:50',Rule::unique('tax_codes','code')->ignore($id)],
            'name' => ['required','string','max:150'],
            'tax_type' => ['required','in:withholding_payable,input_tax_receivable,output_tax_payable'],
            'rate_percent' => ['required','numeric','min:0','max:100'],
            'chart_of_account_id' => ['required','integer','exists:chart_of_accounts,id'],
            'effective_from' => ['nullable','date'],
            'effective_to' => ['nullable','date','after_or_equal:effective_from'],
            'certificate_required' => ['nullable','boolean'],
            'is_active' => ['nullable','boolean'],
            'notes' => ['nullable','string','max:5000'],
        ]);
    }

    private function validateAccountMapping($taxType, $accountId)
    {
        $account = ChartOfAccount::findOrFail($accountId);

        if (!$account->is_active || $account->children()->exists()) {
            throw ValidationException::withMessages([
                'chart_of_account_id' => ['Choose an active posting-level GL account.'],
            ]);
        }

        if ($taxType === 'input_tax_receivable') {
            if ($account->account_type !== 'asset' || $account->normal_balance !== 'debit') {
                throw ValidationException::withMessages([
                    'chart_of_account_id' => ['Input tax receivable must map to a debit-normal asset account.'],
                ]);
            }
            return;
        }

        if ($account->account_type !== 'liability' || $account->normal_balance !== 'credit') {
            throw ValidationException::withMessages([
                'chart_of_account_id' => ['Withholding/output tax must map to a credit-normal liability account.'],
            ]);
        }
    }

    private function ledgerPositions($to, $branchId, $taxCodeId = null, $taxType = null)
    {
        $codes = TaxCode::with('account:id,code,name,account_type,normal_balance')
            ->when($taxCodeId, function ($query) use ($taxCodeId) {
                $query->whereKey($taxCodeId);
            })
            ->when($taxType, function ($query) use ($taxType) {
                $query->where('tax_type', $taxType);
            })
            ->orderBy('code')
            ->get();

        return $codes->map(function ($code) use ($to, $branchId) {
            $tx = TaxTransaction::where('tax_code_id', $code->id)
                ->where('status', 'active')
                ->whereDate('transaction_date', '<=', $to)
                ->when($branchId, function ($query) use ($branchId) {
                    $query->where('branch_id', $branchId);
                })
                ->sum('tax_amount');

            $gl = DB::table('journal_lines as l')
                ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->leftJoin('projects as p', 'p.id', '=', 'l.project_id')
                ->leftJoin('customers as c', 'c.id', '=', 'l.customer_id')
                ->where('e.status', 'posted')
                ->where('l.chart_of_account_id', $code->chart_of_account_id)
                ->whereDate('e.entry_date', '<=', $to)
                ->when($branchId, function ($query) use ($branchId) {
                    $query->where(function ($scope) use ($branchId) {
                        $scope->where('p.branch_id', $branchId)
                            ->orWhere('c.branch_id', $branchId);
                    });
                })
                ->selectRaw('COALESCE(SUM(l.debit),0) as debit, COALESCE(SUM(l.credit),0) as credit')
                ->first();

            $ledgerBalance = $code->tax_type === 'input_tax_receivable'
                ? round((float) $gl->debit - (float) $gl->credit, 2)
                : round((float) $gl->credit - (float) $gl->debit, 2);

            $register = round((float) $tx, 2);

            return [
                'tax_code_id' => $code->id,
                'code' => $code->code,
                'name' => $code->name,
                'tax_type' => $code->tax_type,
                'account_code' => optional($code->account)->code,
                'account_name' => optional($code->account)->name,
                'register_amount' => $this->money($register),
                'ledger_balance' => $this->money($ledgerBalance),
                'difference' => $this->money($ledgerBalance - $register),
            ];
        })->values();
    }

    private function ensureTransactionAccess(TaxTransaction $transaction)
    {
        if ($this->canAccessAllBranches()) return;

        $branchId = auth()->user()->branch_id;
        abort_unless($branchId && (int) $transaction->branch_id === (int) $branchId, 403);
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

    private function money($value)
    {
        return number_format((float) $value, 2, '.', '');
    }
}
