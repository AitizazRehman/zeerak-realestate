<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\ChartOfAccount;
use App\Models\Vendor;
use App\Models\VendorBill;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountsPayableReportController extends Controller
{
    use ChecksBranchAccess;

    private function filters(Request $request, $statement = false)
    {
        return $request->validate([
            'as_of' => ['nullable','date'],
            'from' => [$statement ? 'required' : 'nullable','date'],
            'to' => [$statement ? 'required' : 'nullable','date','after_or_equal:from'],
            'branch_id' => ['nullable','integer','exists:branches,id'],
            'vendor_id' => [$statement ? 'required' : 'nullable','integer','exists:vendors,id'],
            'project_id' => ['nullable','integer','exists:projects,id'],
        ]);
    }

    private function scopedBills(array $filters)
    {
        $query = VendorBill::with([
            'vendor:id,vendor_number,name,branch_id',
            'project:id,name,code',
            'lines:id,vendor_bill_id,project_id,amount',
            'payments' => function ($payments) {
                $payments->orderBy('payment_date')->orderBy('id');
            },
            'payments.allocations:id,vendor_bill_payment_id,project_id,amount',
        ]);

        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;

            if (!$branchId) {
                abort(403, 'Your user account is not assigned to a branch.');
            }

            $query->where('branch_id', $branchId);
        } elseif (!empty($filters['branch_id'])) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }

        if (!empty($filters['vendor_id'])) {
            $query->where('vendor_id', (int) $filters['vendor_id']);
        }

        if (!empty($filters['project_id'])) {
            $projectId = (int) $filters['project_id'];

            $query->where(function ($bill) use ($projectId) {
                $bill->where('project_id', $projectId)
                    ->orWhereHas('lines', function ($line) use ($projectId) {
                        $line->where('project_id', $projectId);
                    });
            });
        }

        return $query;
    }

    public function statement(Request $request)
    {
        $filters = $this->filters($request, true);
        $from = Carbon::parse($filters['from'])->startOfDay();
        $to = Carbon::parse($filters['to'])->endOfDay();

        $vendor = Vendor::withTrashed()->findOrFail($filters['vendor_id']);
        $this->assertVendorAccess($vendor, $filters);

        $bills = $this->scopedBills($filters)
            ->whereDate('bill_date', '<=', $to->toDateString())
            ->orderBy('bill_date')
            ->orderBy('id')
            ->get();

        $events = $this->events($bills, $to, $filters['project_id'] ?? null)
            ->sortBy(function ($event) {
                return $event['date'].'|'.str_pad((string) $event['order'], 12, '0', STR_PAD_LEFT);
            })
            ->values();

        $opening = 0.0;
        $periodDebit = 0.0;
        $periodCredit = 0.0;
        $running = 0.0;
        $rows = [];

        foreach ($events as $event) {
            $credit = (float) $event['credit'];
            $debit = (float) $event['debit'];
            $movement = round($credit - $debit, 2);

            if ($event['date'] < $from->toDateString()) {
                $opening = round($opening + $movement, 2);
                $running = $opening;
                continue;
            }

            if ($event['date'] > $to->toDateString()) {
                continue;
            }

            $periodDebit = round($periodDebit + $debit, 2);
            $periodCredit = round($periodCredit + $credit, 2);
            $running = round($running + $movement, 2);

            $rows[] = array_merge($event, [
                'debit' => $this->money($debit),
                'credit' => $this->money($credit),
                'balance' => $this->money($running),
            ]);
        }

        return response()->json([
            'vendor' => $vendor->only([
                'id','vendor_number','branch_id','name','contact_person','phone','email',
                'tax_number','address','city','payment_terms_days',
            ]),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'project_id' => $filters['project_id'] ?? null,
            ],
            'summary' => [
                'opening' => $this->money($opening),
                'debit' => $this->money($periodDebit),
                'credit' => $this->money($periodCredit),
                'closing' => $this->money($opening + $periodCredit - $periodDebit),
            ],
            'entries' => $rows,
        ]);
    }

    public function forecast(Request $request)
    {
        $filters = $this->filters($request);
        $asOf = !empty($filters['as_of'])
            ? Carbon::parse($filters['as_of'])->startOfDay()
            : now()->startOfDay();

        $bills = $this->scopedBills($filters)
            ->whereDate('bill_date', '<=', $asOf->toDateString())
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        $rows = $this->balancesAsOf($bills, $asOf, $filters['project_id'] ?? null)
            ->filter(function ($row) {
                return (float) $row['balance'] > 0.009;
            })
            ->map(function ($row) use ($asOf) {
                $due = Carbon::parse($row['due_date'])->startOfDay();
                $bucket = $this->forecastBucket($due, $asOf);
                $days = $due->lt($asOf) ? -$due->diffInDays($asOf) : $asOf->diffInDays($due);

                return array_merge($row, [
                    'forecast_bucket' => $bucket,
                    'days_to_due' => $days,
                    'balance' => $this->money($row['balance']),
                ]);
            })
            ->values();

        $summary = [
            'total' => 0.0,
            'overdue' => 0.0,
            'due_today' => 0.0,
            'next_7' => 0.0,
            'days_8_30' => 0.0,
            'days_31_60' => 0.0,
            'days_61_90' => 0.0,
            'later' => 0.0,
        ];

        foreach ($rows as $row) {
            $amount = (float) $row['balance'];
            $summary['total'] = round($summary['total'] + $amount, 2);
            $summary[$row['forecast_bucket']] = round($summary[$row['forecast_bucket']] + $amount, 2);
        }

        $vendors = $rows->groupBy('vendor_id')->map(function ($items) {
            $first = $items->first();
            $result = [
                'vendor_id' => $first['vendor_id'],
                'vendor_number' => $first['vendor_number'],
                'vendor_name' => $first['vendor_name'],
                'total' => 0.0,
                'overdue' => 0.0,
                'due_today' => 0.0,
                'next_7' => 0.0,
                'days_8_30' => 0.0,
                'days_31_60' => 0.0,
                'days_61_90' => 0.0,
                'later' => 0.0,
                'next_due_date' => $items->pluck('due_date')->sort()->first(),
                'bill_count' => $items->count(),
            ];

            foreach ($items as $item) {
                $amount = (float) $item['balance'];
                $result['total'] = round($result['total'] + $amount, 2);
                $result[$item['forecast_bucket']] = round($result[$item['forecast_bucket']] + $amount, 2);
            }

            foreach (['total','overdue','due_today','next_7','days_8_30','days_31_60','days_61_90','later'] as $field) {
                $result[$field] = $this->money($result[$field]);
            }

            return $result;
        })->sortByDesc(function ($row) {
            return (float) $row['overdue'] + (float) $row['due_today'] + (float) $row['next_7'];
        })->values();

        foreach ($summary as $field => $value) {
            $summary[$field] = $this->money($value);
        }

        $summary['vendor_count'] = $vendors->count();
        $summary['bill_count'] = $rows->count();

        return response()->json([
            'as_of' => $asOf->toDateString(),
            'summary' => $summary,
            'vendors' => $vendors,
            'bills' => $rows,
        ]);
    }

    public function reconciliation(Request $request)
    {
        $filters = $this->filters($request);
        $asOf = !empty($filters['as_of'])
            ? Carbon::parse($filters['as_of'])->startOfDay()
            : now()->startOfDay();

        $bills = $this->scopedBills($filters)
            ->whereDate('bill_date', '<=', $asOf->toDateString())
            ->get();

        $projectId = $filters['project_id'] ?? null;
        $balances = $this->balancesAsOf($bills, $asOf, $projectId);
        $subledger = round((float) $balances->sum('balance'), 2);
        $managedGl = $this->managedGlBalance($bills, $asOf, $projectId);
        $managedDifference = round($managedGl - $subledger, 2);

        $unfiltered = empty($filters['branch_id']) &&
            empty($filters['vendor_id']) &&
            empty($filters['project_id']) &&
            $this->canAccessAllBranches();

        $totalGl = $unfiltered ? $this->totalPayableGlBalance($asOf) : null;
        $otherActivity = $totalGl === null ? null : round($totalGl - $managedGl, 2);
        $overallDifference = $totalGl === null ? null : round($totalGl - $subledger, 2);

        return response()->json([
            'as_of' => $asOf->toDateString(),
            'subledger_balance' => $this->money($subledger),
            'managed_gl_balance' => $this->money($managedGl),
            'managed_difference' => $this->money($managedDifference),
            'managed_balanced' => abs($managedDifference) <= 0.01,
            'total_gl_balance' => $totalGl === null ? null : $this->money($totalGl),
            'other_gl_activity' => $otherActivity === null ? null : $this->money($otherActivity),
            'overall_difference' => $overallDifference === null ? null : $this->money($overallDifference),
            'overall_balanced' => $overallDifference === null ? null : abs($overallDifference) <= 0.01,
            'bill_count' => $balances->filter(function ($row) {
                return (float) $row['balance'] > 0.009;
            })->count(),
            'scope_is_global' => $unfiltered,
            'note' => $unfiltered
                ? 'Total GL includes every posted entry to system account 2100. Other GL activity highlights postings outside the vendor AP workflow.'
                : 'Filtered reconciliation compares the vendor subledger with 2100 journal activity generated by the matching AP workflow records in this scope.',
        ]);
    }

    private function assertVendorAccess(Vendor $vendor, array $filters)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;

            if (!$branchId || (int) $vendor->branch_id !== (int) $branchId) {
                abort(404);
            }
        }

        if (!empty($filters['branch_id']) && (int) $vendor->branch_id !== (int) $filters['branch_id']) {
            abort(404);
        }
    }

    private function events(Collection $bills, Carbon $to, $projectId = null)
    {
        $events = collect();
        $order = 0;

        foreach ($bills as $bill) {
            $billAmount = $this->billAmountForProject($bill, $projectId);

            if ($billAmount <= 0.009) {
                continue;
            }

            if ($bill->bill_date->lte($to)) {
                $events->push([
                    'date' => $bill->bill_date->toDateString(),
                    'order' => ++$order,
                    'type' => 'bill',
                    'reference' => $bill->bill_number,
                    'vendor_invoice_number' => $bill->vendor_invoice_number,
                    'bill_id' => $bill->id,
                    'payment_id' => null,
                    'project_id' => $bill->project_id,
                    'project_name' => optional($bill->project)->name,
                    'description' => $bill->description,
                    'debit' => 0.0,
                    'credit' => $this->moneyNumber($billAmount),
                ]);
            }

            foreach ($bill->payments as $payment) {
                $paymentAmount = $this->paymentAmountForProject($payment, $bill, $projectId);

                if ($paymentAmount <= 0.009) {
                    continue;
                }

                if ($payment->payment_date->lte($to)) {
                    $events->push([
                        'date' => $payment->payment_date->toDateString(),
                        'order' => ++$order,
                        'type' => 'payment',
                        'reference' => $payment->reference_number ?: ($payment->cheque_number ?: 'Payment #'.$payment->id),
                        'vendor_invoice_number' => $bill->vendor_invoice_number,
                        'bill_id' => $bill->id,
                        'payment_id' => $payment->id,
                        'project_id' => $bill->project_id,
                        'project_name' => optional($bill->project)->name,
                        'description' => 'Payment against '.$bill->bill_number,
                        'debit' => $this->moneyNumber($paymentAmount),
                        'credit' => 0.0,
                    ]);
                }

                if ($payment->reversed_at && $payment->reversal_date && Carbon::parse($payment->reversal_date)->lte($to)) {
                    $events->push([
                        'date' => Carbon::parse($payment->reversal_date)->toDateString(),
                        'order' => ++$order,
                        'type' => 'payment_reversal',
                        'reference' => 'REV-PAY-'.$payment->id,
                        'vendor_invoice_number' => $bill->vendor_invoice_number,
                        'bill_id' => $bill->id,
                        'payment_id' => $payment->id,
                        'project_id' => $bill->project_id,
                        'project_name' => optional($bill->project)->name,
                        'description' => $payment->reversal_reason ?: 'Vendor payment reversal',
                        'debit' => 0.0,
                        'credit' => $this->moneyNumber($paymentAmount),
                    ]);
                }
            }

            if ($bill->status === 'cancelled' &&
                $bill->cancellation_date &&
                Carbon::parse($bill->cancellation_date)->lte($to)) {
                $events->push([
                    'date' => Carbon::parse($bill->cancellation_date)->toDateString(),
                    'order' => ++$order,
                    'type' => 'bill_cancellation',
                    'reference' => 'CANCEL-'.$bill->bill_number,
                    'vendor_invoice_number' => $bill->vendor_invoice_number,
                    'bill_id' => $bill->id,
                    'payment_id' => null,
                    'project_id' => $bill->project_id,
                    'project_name' => optional($bill->project)->name,
                    'description' => $bill->cancellation_reason ?: 'Vendor bill cancellation',
                    'debit' => $this->moneyNumber($billAmount),
                    'credit' => 0.0,
                ]);
            }
        }

        return $events;
    }

    private function balancesAsOf(Collection $bills, Carbon $asOf, $projectId = null)
    {
        return $bills->map(function ($bill) use ($asOf, $projectId) {
            $billAmount = $this->billAmountForProject($bill, $projectId);
            $balance = $bill->bill_date->lte($asOf)
                ? $billAmount
                : 0.0;

            foreach ($bill->payments as $payment) {
                $paymentAmount = $this->paymentAmountForProject($payment, $bill, $projectId);

                if ($payment->payment_date->lte($asOf)) {
                    $balance = round($balance - $paymentAmount, 2);
                }

                if ($payment->reversed_at &&
                    $payment->reversal_date &&
                    Carbon::parse($payment->reversal_date)->lte($asOf)) {
                    $balance = round($balance + $paymentAmount, 2);
                }
            }

            if ($bill->status === 'cancelled' &&
                $bill->cancellation_date &&
                Carbon::parse($bill->cancellation_date)->lte($asOf)) {
                $balance = round($balance - $billAmount, 2);
            }

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
                'balance' => max(0, round($balance, 2)),
            ];
        });
    }

    private function managedGlBalance(Collection $bills, Carbon $asOf, $projectId = null)
    {
        $account = $this->payableAccount();
        $billIds = $bills->pluck('id')->map(function ($id) { return (int) $id; })->values();
        $paymentIds = $bills->flatMap(function ($bill) {
            return $bill->payments->pluck('id');
        })->map(function ($id) { return (int) $id; })->values();

        if (!$billIds->count() && !$paymentIds->count()) {
            return 0.0;
        }

        $query = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.status', 'posted')
            ->where('e.entry_date', '<=', $asOf->toDateString())
            ->where('l.chart_of_account_id', $account->id)
            ->when($projectId, function ($query) use ($projectId) {
                $query->where('l.project_id', (int) $projectId);
            })
            ->where(function ($source) use ($billIds, $paymentIds) {
                if ($billIds->count()) {
                    $source->where(function ($bills) use ($billIds) {
                        $bills->whereIn('e.source_type', ['vendor_bill','vendor_bill_cancel'])
                            ->whereIn('e.source_id', $billIds);
                    });
                }

                if ($paymentIds->count()) {
                    $method = $billIds->count() ? 'orWhere' : 'where';
                    $source->$method(function ($payments) use ($paymentIds) {
                        $payments->whereIn('e.source_type', ['vendor_bill_payment','vendor_bill_payment_reverse'])
                            ->whereIn('e.source_id', $paymentIds);
                    });
                }
            })
            ->selectRaw('COALESCE(SUM(l.credit - l.debit), 0) as balance')
            ->first();

        return round((float) $query->balance, 2);
    }

    private function totalPayableGlBalance(Carbon $asOf)
    {
        $account = $this->payableAccount();

        $row = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.status', 'posted')
            ->where('e.entry_date', '<=', $asOf->toDateString())
            ->where('l.chart_of_account_id', $account->id)
            ->selectRaw('COALESCE(SUM(l.credit - l.debit), 0) as balance')
            ->first();

        return round((float) $row->balance, 2);
    }


    private function billAmountForProject($bill, $projectId = null)
    {
        if (!$projectId) {
            return round((float) $bill->total_amount, 2);
        }

        return round((float) $bill->lines->sum(function ($line) use ($bill, $projectId) {
            $effectiveProject = $line->project_id ?: $bill->project_id;

            return (int) $effectiveProject === (int) $projectId
                ? (float) $line->amount
                : 0.0;
        }), 2);
    }

    private function paymentAmountForProject($payment, $bill, $projectId = null)
    {
        if (!$projectId) {
            return round((float) $payment->amount, 2);
        }

        if ($payment->relationLoaded('allocations') && $payment->allocations->count()) {
            return round((float) $payment->allocations->sum(function ($allocation) use ($projectId) {
                return (int) $allocation->project_id === (int) $projectId
                    ? (float) $allocation->amount
                    : 0.0;
            }), 2);
        }

        return (int) $bill->project_id === (int) $projectId
            ? round((float) $payment->amount, 2)
            : 0.0;
    }

    private function moneyNumber($value)
    {
        return round((float) $value, 2);
    }

    private function payableAccount()
    {
        $account = ChartOfAccount::where('code', '2100')
            ->where('account_type', 'liability')
            ->where('is_system', true)
            ->first();

        if (!$account) {
            abort(422, 'System account 2100 Accounts Payable is missing.');
        }

        return $account;
    }

    private function forecastBucket(Carbon $due, Carbon $asOf)
    {
        if ($due->lt($asOf)) return 'overdue';
        if ($due->equalTo($asOf)) return 'due_today';

        $days = $asOf->diffInDays($due);
        if ($days <= 7) return 'next_7';
        if ($days <= 30) return 'days_8_30';
        if ($days <= 60) return 'days_31_60';
        if ($days <= 90) return 'days_61_90';
        return 'later';
    }

    private function money($value)
    {
        return number_format((float) $value, 2, '.', '');
    }
}
