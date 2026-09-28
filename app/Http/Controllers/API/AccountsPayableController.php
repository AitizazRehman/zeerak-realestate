<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Branch;
use App\Models\Project;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Services\PaymentAccountingService;
use App\Services\VendorPayableAccountingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountsPayableController extends Controller
{
    use ChecksBranchAccess;

    private function scopeBranch($query, $column = 'branch_id')
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;

            if (!$branchId) {
                abort(403, 'Your user account is not assigned to a branch.');
            }

            $query->where($column, $branchId);
        }

        return $query;
    }

    public function options(Request $request, VendorPayableAccountingService $accounting, PaymentAccountingService $payments)
    {
        $request->validate([
            'branch_id' => ['nullable','integer','exists:branches,id'],
        ]);

        $branchId = $this->canAccessAllBranches()
            ? ($request->filled('branch_id') ? (int) $request->branch_id : null)
            : (int) auth()->user()->branch_id;

        $vendors = Vendor::where('is_active', true)
            ->when($branchId, function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            })
            ->orderBy('name')
            ->get(['id','branch_id','vendor_number','name','payment_terms_days']);

        $projects = Project::where('is_active', true)
            ->when($branchId, function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            })
            ->orderBy('name')
            ->get(['id','branch_id','name','code']);

        $branches = $this->canAccessAllBranches()
            ? Branch::where('is_active', true)->orderBy('name')->get(['id','name','code'])
            : collect();

        return response()->json([
            'vendors' => $vendors,
            'projects' => $projects,
            'branches' => $branches,
            'debit_accounts' => $accounting->debitAccounts()->map->only(['id','code','name','account_type'])->values(),
            'cash_accounts' => $payments->accounts()->map->only(['id','code','name'])->values(),
        ]);
    }

    public function index(Request $request)
    {
        $request->validate([
            'branch_id' => ['nullable','integer','exists:branches,id'],
            'vendor_id' => ['nullable','integer','exists:vendors,id'],
            'project_id' => ['nullable','integer','exists:projects,id'],
            'status' => ['nullable','in:posted,partial,paid,cancelled,overdue'],
            'from' => ['nullable','date'],
            'to' => ['nullable','date'],
            'search' => ['nullable','string','max:150'],
            'per_page' => ['nullable','integer','min:1','max:100'],
        ]);

        $query = $this->scopeBranch(
            VendorBill::with([
                'vendor:id,vendor_number,name',
                'branch:id,name,code',
                'project:id,name,code',
                'lines.account:id,code,name',
                'payments' => function ($q) {
                    $q->latest('payment_date')->latest('id');
                },
                'payments.cashBankAccount:id,code,name',
                'journalEntry:id,source_id,entry_number,status',
            ])
        );

        if ($request->filled('branch_id') && $this->canAccessAllBranches()) {
            $query->where('branch_id', (int) $request->branch_id);
        }
        if ($request->filled('vendor_id')) $query->where('vendor_id', (int) $request->vendor_id);
        if ($request->filled('project_id')) $query->where('project_id', (int) $request->project_id);
        if ($request->filled('from')) $query->whereDate('bill_date', '>=', $request->from);
        if ($request->filled('to')) $query->whereDate('bill_date', '<=', $request->to);

        if ($request->filled('status')) {
            if ($request->status === 'overdue') {
                $query->whereIn('status', ['posted','partial'])
                    ->where('remaining_amount', '>', 0)
                    ->whereDate('due_date', '<', now()->toDateString());
            } else {
                $query->where('status', $request->status);
            }
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('bill_number', 'like', "%{$search}%")
                    ->orWhere('vendor_invoice_number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('vendor', function ($vendor) use ($search) {
                        $vendor->where('name', 'like', "%{$search}%")
                            ->orWhere('vendor_number', 'like', "%{$search}%");
                    });
            });
        }

        return response()->json(
            $query->orderByDesc('bill_date')
                ->orderByDesc('id')
                ->paginate(min(max((int) $request->get('per_page', 25), 1), 100))
        );
    }

    public function aging(Request $request)
    {
        $request->validate([
            'as_of' => ['nullable','date'],
            'branch_id' => ['nullable','integer','exists:branches,id'],
            'vendor_id' => ['nullable','integer','exists:vendors,id'],
            'project_id' => ['nullable','integer','exists:projects,id'],
        ]);

        $asOf = $request->filled('as_of') ? Carbon::parse($request->as_of)->startOfDay() : now()->startOfDay();

        $query = $this->scopeBranch(
            VendorBill::with(['vendor:id,vendor_number,name','project:id,name,code'])
                ->whereIn('status', ['posted','partial'])
                ->where('remaining_amount', '>', 0)
        );

        if ($request->filled('branch_id') && $this->canAccessAllBranches()) $query->where('branch_id', (int) $request->branch_id);
        if ($request->filled('vendor_id')) $query->where('vendor_id', (int) $request->vendor_id);
        if ($request->filled('project_id')) $query->where('project_id', (int) $request->project_id);

        $rows = $query->orderBy('due_date')->get()->map(function ($bill) use ($asOf) {
            $bucket = $this->bucket($bill->due_date, $asOf);
            return [
                'bill_id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'vendor_invoice_number' => $bill->vendor_invoice_number,
                'vendor_id' => $bill->vendor_id,
                'vendor_number' => optional($bill->vendor)->vendor_number,
                'vendor_name' => optional($bill->vendor)->name,
                'project_id' => $bill->project_id,
                'project_code' => optional($bill->project)->code,
                'project_name' => optional($bill->project)->name,
                'bill_date' => $bill->bill_date->toDateString(),
                'due_date' => $bill->due_date->toDateString(),
                'remaining_amount' => number_format((float) $bill->remaining_amount, 2, '.', ''),
                'bucket' => $bucket,
                'days_overdue' => $bill->due_date->lt($asOf) ? $bill->due_date->diffInDays($asOf) : 0,
            ];
        });

        $summary = ['total'=>0,'current'=>0,'1_30'=>0,'31_60'=>0,'61_90'=>0,'90_plus'=>0];

        foreach ($rows as $row) {
            $amount = (float) $row['remaining_amount'];
            $summary['total'] += $amount;
            $summary[$row['bucket']] += $amount;
        }

        $vendorRows = $rows->groupBy('vendor_id')->map(function ($items) {
            $first = $items->first();
            $result = [
                'vendor_id' => $first['vendor_id'],
                'vendor_number' => $first['vendor_number'],
                'vendor_name' => $first['vendor_name'],
                'bill_count' => $items->count(),
                'total' => 0,'current'=>0,'1_30'=>0,'31_60'=>0,'61_90'=>0,'90_plus'=>0,
                'max_days_overdue' => (int) $items->max('days_overdue'),
            ];

            foreach ($items as $item) {
                $amount = (float) $item['remaining_amount'];
                $result['total'] += $amount;
                $result[$item['bucket']] += $amount;
            }

            foreach (['total','current','1_30','31_60','61_90','90_plus'] as $field) {
                $result[$field] = number_format($result[$field], 2, '.', '');
            }

            return $result;
        })->sortByDesc(function ($row) {
            return (float) $row['90_plus'] + (float) $row['61_90'] + (float) $row['31_60'] + (float) $row['1_30'];
        })->values();

        foreach ($summary as $key => $value) {
            $summary[$key] = number_format($value, 2, '.', '');
        }

        $summary['overdue'] = number_format(
            (float) $summary['1_30'] + (float) $summary['31_60'] + (float) $summary['61_90'] + (float) $summary['90_plus'],
            2,
            '.',
            ''
        );
        $summary['vendor_count'] = $vendorRows->count();
        $summary['bill_count'] = $rows->count();

        return response()->json([
            'as_of' => $asOf->toDateString(),
            'summary' => $summary,
            'vendors' => $vendorRows,
            'bills' => $rows->values(),
        ]);
    }

    public function store(Request $request, VendorPayableAccountingService $accounting)
    {
        $data = $request->validate([
            'vendor_id' => ['required','integer','exists:vendors,id'],
            'branch_id' => ['nullable','integer','exists:branches,id'],
            'project_id' => ['nullable','integer','exists:projects,id'],
            'vendor_invoice_number' => ['nullable','string','max:100'],
            'bill_date' => ['required','date'],
            'due_date' => ['required','date','after_or_equal:bill_date'],
            'description' => ['required','string','max:500'],
            'notes' => ['nullable','string','max:5000'],
            'lines' => ['required','array','min:1','max:100'],
            'lines.*.chart_of_account_id' => ['required','integer','exists:chart_of_accounts,id'],
            'lines.*.project_id' => ['nullable','integer','exists:projects,id'],
            'lines.*.description' => ['required','string','max:500'],
            'lines.*.amount' => ['required','numeric','min:0.01'],
        ]);

        $bill = DB::transaction(function () use ($data, $request, $accounting) {
            $vendor = Vendor::where('is_active', true)->lockForUpdate()->findOrFail($data['vendor_id']);
            $branchId = $this->canAccessAllBranches()
                ? (int) ($data['branch_id'] ?? $vendor->branch_id)
                : (int) auth()->user()->branch_id;

            if (!$branchId || (int) $vendor->branch_id !== $branchId) {
                throw ValidationException::withMessages([
                    'vendor_id' => ['Vendor must belong to the selected branch.'],
                ]);
            }

            if (!$this->canAccessAllBranches() && (int) auth()->user()->branch_id !== $branchId) {
                abort(403);
            }

            if (!empty($data['project_id'])) {
                $project = Project::where('is_active', true)->findOrFail($data['project_id']);
                if ((int) $project->branch_id !== $branchId) {
                    throw ValidationException::withMessages([
                        'project_id' => ['Project must belong to the vendor bill branch.'],
                    ]);
                }
            }

            $allowedAccounts = $accounting->debitAccounts()->keyBy('id');
            $total = 0;

            foreach ($data['lines'] as $line) {
                if (!$allowedAccounts->has((int) $line['chart_of_account_id'])) {
                    throw ValidationException::withMessages([
                        'lines' => ['Each bill line must use an active posting-level asset, expense, or cost-of-sales account.'],
                    ]);
                }

                if (!empty($line['project_id'])) {
                    $project = Project::where('is_active', true)->findOrFail($line['project_id']);
                    if ((int) $project->branch_id !== $branchId) {
                        throw ValidationException::withMessages([
                            'lines' => ['Every line project must belong to the bill branch.'],
                        ]);
                    }
                }

                $total = round($total + round((float) $line['amount'], 2), 2);
            }

            if ($total <= 0) {
                abort(422, 'Vendor bill total must be greater than zero.');
            }

            if (!empty($data['vendor_invoice_number']) &&
                VendorBill::where('vendor_id', $vendor->id)
                    ->where('vendor_invoice_number', $data['vendor_invoice_number'])
                    ->exists()) {
                throw ValidationException::withMessages([
                    'vendor_invoice_number' => ['This vendor invoice number has already been recorded.'],
                ]);
            }

            do {
                $billNumber = 'BILL-'.now()->format('Ym').'-'.strtoupper(Str::random(8));
            } while (VendorBill::withTrashed()->where('bill_number', $billNumber)->exists());

            $bill = VendorBill::create([
                'vendor_id' => $vendor->id,
                'branch_id' => $branchId,
                'project_id' => $data['project_id'] ?? null,
                'bill_number' => $billNumber,
                'vendor_invoice_number' => $data['vendor_invoice_number'] ?? null,
                'bill_date' => $data['bill_date'],
                'due_date' => $data['due_date'],
                'description' => $data['description'],
                'total_amount' => $total,
                'paid_amount' => 0,
                'remaining_amount' => $total,
                'status' => 'posted',
                'created_by' => $request->user()->id,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['lines'] as $line) {
                $bill->lines()->create([
                    'chart_of_account_id' => (int) $line['chart_of_account_id'],
                    'project_id' => $line['project_id'] ?? $bill->project_id,
                    'description' => $line['description'],
                    'amount' => round((float) $line['amount'], 2),
                ]);
            }

            $accounting->postBill($bill, $request->user()->id);

            return $bill;
        });

        return response()->json([
            'message' => 'Vendor bill posted successfully.',
            'bill' => $bill->fresh()->load([
                'vendor:id,vendor_number,name',
                'project:id,name,code',
                'lines.account:id,code,name',
                'journalEntry:id,entry_number,status',
            ]),
        ], 201);
    }

    public function pay(Request $request, VendorBill $vendorBill, VendorPayableAccountingService $accounting)
    {
        $bill = $this->scopeBranch(VendorBill::query())->findOrFail($vendorBill->id);

        $data = $request->validate([
            'amount' => ['required','numeric','min:0.01'],
            'cash_bank_account_id' => ['required','integer','exists:chart_of_accounts,id'],
            'payment_date' => ['required','date','before_or_equal:today'],
            'payment_method' => ['required','in:cash,bank_transfer,cheque,online,other'],
            'reference_number' => ['nullable','string','max:150'],
            'cheque_number' => ['nullable','string','max:100'],
            'notes' => ['nullable','string','max:5000'],
            'request_key' => ['required','string','max:100'],
        ]);

        $payment = $accounting->payBill($bill, $data, $request->user()->id);

        return response()->json([
            'message' => 'Vendor payment posted successfully.',
            'payment' => $payment->fresh()->load([
                'cashBankAccount:id,code,name',
                'journalEntry:id,entry_number,status',
                'paidBy:id,name',
            ]),
            'bill' => $bill->fresh(),
        ], 201);
    }

    public function reversePayment(Request $request, VendorBillPayment $vendorBillPayment, VendorPayableAccountingService $accounting)
    {
        $payment = VendorBillPayment::whereKey($vendorBillPayment->id)
            ->whereHas('bill', function ($bill) {
                if (!$this->canAccessAllBranches()) {
                    $bill->where('branch_id', auth()->user()->branch_id);
                }
            })
            ->firstOrFail();

        $data = $request->validate([
            'reversal_date' => ['required','date','before_or_equal:today'],
            'reason' => ['required','string','min:5','max:1000'],
        ]);

        $entry = $accounting->reversePayment(
            $payment,
            $data['reversal_date'],
            $data['reason'],
            $request->user()->id
        );

        return response()->json([
            'message' => 'Vendor payment reversed successfully.',
            'reversal' => $entry->load('lines.account:id,code,name'),
            'bill' => $payment->bill()->first()->fresh(),
        ]);
    }

    public function cancel(Request $request, VendorBill $vendorBill, VendorPayableAccountingService $accounting)
    {
        $bill = $this->scopeBranch(VendorBill::query())->findOrFail($vendorBill->id);

        $data = $request->validate([
            'cancellation_date' => ['required','date','before_or_equal:today'],
            'reason' => ['required','string','min:5','max:1000'],
        ]);

        $entry = $accounting->cancelBill(
            $bill,
            $data['cancellation_date'],
            $data['reason'],
            $request->user()->id
        );

        return response()->json([
            'message' => 'Vendor bill cancelled successfully.',
            'bill' => $bill->fresh(),
            'reversal' => $entry,
        ]);
    }

    private function bucket($dueDate, Carbon $asOf)
    {
        $dueDate = Carbon::parse($dueDate)->startOfDay();

        if ($dueDate->gte($asOf)) return 'current';

        $days = $dueDate->diffInDays($asOf);
        if ($days <= 30) return '1_30';
        if ($days <= 60) return '31_60';
        if ($days <= 90) return '61_90';
        return '90_plus';
    }
}
