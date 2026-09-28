<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\BankAccount;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Installment;
use App\Models\Project;
use App\Models\TreasuryCommitment;
use App\Models\VendorBill;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TreasuryController extends Controller
{
    use ChecksBranchAccess;

    public function options()
    {
        $branchId = $this->userBranchId();

        $branches = $this->canAccessAllBranches()
            ? Branch::where('is_active', true)->orderBy('name')->get(['id','name','code'])
            : Branch::whereKey($branchId)->get(['id','name','code']);

        $projects = Project::where('is_active', true)
            ->when($branchId, function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->orderBy('name')
            ->get(['id','branch_id','name','code']);

        $accounts = BankAccount::with('chartOfAccount:id,code,name')
            ->where('is_active', true)
            ->when($branchId, function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->orderBy('name')
            ->get(['id','branch_id','chart_of_account_id','account_type','name','bank_name','currency']);

        return response()->json([
            'branches' => $branches,
            'projects' => $projects,
            'bank_accounts' => $accounts,
            'commitment_categories' => [
                'Payroll',
                'Tax / Government',
                'Construction',
                'Utilities',
                'Marketing',
                'Professional Fees',
                'Debt / Financing',
                'Owner / Equity',
                'Security Deposit',
                'Other',
            ],
        ]);
    }

    public function commitments(Request $request)
    {
        $request->validate([
            'branch_id' => ['nullable','integer','exists:branches,id'],
            'project_id' => ['nullable','integer','exists:projects,id'],
            'flow_type' => ['nullable','in:inflow,outflow'],
            'status' => ['nullable','in:planned,realized,cancelled'],
            'from' => ['nullable','date'],
            'to' => ['nullable','date','after_or_equal:from'],
            'per_page' => ['nullable','integer','min:1','max:100'],
        ]);

        $query = $this->commitmentScope(
            TreasuryCommitment::with([
                'branch:id,name,code',
                'project:id,name,code',
                'bankAccount:id,name,bank_name,account_type',
                'createdBy:id,name',
            ]),
            $request
        );

        if ($request->filled('flow_type')) $query->where('flow_type', $request->flow_type);
        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('from')) $query->whereDate('expected_date', '>=', $request->from);
        if ($request->filled('to')) $query->whereDate('expected_date', '<=', $request->to);

        return response()->json(
            $query->orderBy('expected_date')->orderBy('id')
                ->paginate(min(max((int) $request->get('per_page', 25), 1), 100))
        );
    }

    public function storeCommitment(Request $request)
    {
        $data = $this->validateCommitment($request);
        $data['branch_id'] = $this->resolveBranch($data['branch_id'] ?? null);
        $this->validateCommitmentDimensions($data);

        $commitment = TreasuryCommitment::create(array_merge($data, [
            'status' => 'planned',
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));

        return response()->json([
            'message' => 'Treasury commitment created successfully.',
            'commitment' => $commitment->load([
                'branch:id,name,code',
                'project:id,name,code',
                'bankAccount:id,name,bank_name,account_type',
            ]),
        ], 201);
    }

    public function updateCommitment(Request $request, TreasuryCommitment $treasuryCommitment)
    {
        $commitment = $this->accessibleCommitment($treasuryCommitment->id);

        if ($commitment->status !== 'planned') {
            abort(422, 'Only planned treasury commitments can be edited.');
        }

        $data = $this->validateCommitment($request, true);

        if (!$this->canAccessAllBranches()) {
            unset($data['branch_id']);
        } elseif (array_key_exists('branch_id', $data)) {
            $data['branch_id'] = $this->resolveBranch($data['branch_id']);
        }

        $merged = array_merge($commitment->toArray(), $data);
        $this->validateCommitmentDimensions($merged);

        $commitment->update(array_merge($data, [
            'updated_by' => $request->user()->id,
        ]));

        return response()->json([
            'message' => 'Treasury commitment updated successfully.',
            'commitment' => $commitment->fresh()->load([
                'branch:id,name,code',
                'project:id,name,code',
                'bankAccount:id,name,bank_name,account_type',
            ]),
        ]);
    }

    public function changeCommitmentStatus(Request $request, TreasuryCommitment $treasuryCommitment)
    {
        $commitment = $this->accessibleCommitment($treasuryCommitment->id);

        $data = $request->validate([
            'status' => ['required','in:planned,realized,cancelled'],
            'realized_date' => ['nullable','required_if:status,realized','date','before_or_equal:today'],
        ]);

        if ($data['status'] === 'realized' && $commitment->status === 'cancelled') {
            abort(422, 'A cancelled commitment must be restored to planned before realization.');
        }

        $commitment->update([
            'status' => $data['status'],
            'realized_date' => $data['status'] === 'realized' ? $data['realized_date'] : null,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Treasury commitment status updated successfully.',
            'commitment' => $commitment->fresh(),
        ]);
    }

    public function forecast(Request $request)
    {
        $filters = $request->validate([
            'branch_id' => ['nullable','integer','exists:branches,id'],
            'project_id' => ['nullable','integer','exists:projects,id'],
            'bank_account_id' => ['nullable','integer','exists:bank_accounts,id'],
            'horizon_days' => ['nullable','integer','min:7','max:365'],
            'interval' => ['nullable','in:weekly,monthly'],
            'collection_percent' => ['nullable','numeric','min:0','max:100'],
            'payable_percent' => ['nullable','numeric','min:0','max:100'],
            'use_commitment_probability' => ['nullable','boolean'],
        ]);

        $start = now()->startOfDay();
        $horizonDays = (int) ($filters['horizon_days'] ?? 90);
        $end = $start->copy()->addDays($horizonDays);
        $interval = $filters['interval'] ?? 'weekly';
        $collectionPercent = (float) ($filters['collection_percent'] ?? 100);
        $payablePercent = (float) ($filters['payable_percent'] ?? 100);
        $useProbability = array_key_exists('use_commitment_probability', $filters)
            ? filter_var($filters['use_commitment_probability'], FILTER_VALIDATE_BOOLEAN)
            : true;

        $branchId = $this->resolveForecastBranch($filters['branch_id'] ?? null);
        $projectId = !empty($filters['project_id']) ? (int) $filters['project_id'] : null;
        $bankAccountId = !empty($filters['bank_account_id']) ? (int) $filters['bank_account_id'] : null;

        $this->validateForecastDimensions($branchId, $projectId, $bankAccountId);

        $opening = $this->openingLiquidity($start, $branchId, $bankAccountId);
        $inflows = $this->receivableFlows($start, $end, $branchId, $projectId, $collectionPercent);
        $outflows = $this->payableFlows($start, $end, $branchId, $projectId, $payablePercent);
        $manual = $this->commitmentFlows($start, $end, $branchId, $projectId, $bankAccountId, $useProbability);

        $flows = $inflows['flows']
            ->concat($outflows)
            ->concat($manual)
            ->sortBy('date')
            ->values();

        $periods = $this->periods($start, $end, $interval);
        $running = round((float) $opening['total'], 2);
        $lowestBalance = $running;
        $lowestPeriod = null;

        foreach ($periods as &$period) {
            $periodFlows = $flows->filter(function ($flow) use ($period) {
                return $flow['date'] >= $period['from'] && $flow['date'] <= $period['to'];
            });

            $openingBalance = $running;
            $periodInflows = round((float) $periodFlows->where('flow_type', 'inflow')->sum('forecast_amount'), 2);
            $periodOutflows = round((float) $periodFlows->where('flow_type', 'outflow')->sum('forecast_amount'), 2);
            $net = round($periodInflows - $periodOutflows, 2);

            $datedFlows = $periodFlows
                ->sortBy(function ($flow) {
                    return $flow['date'].'|'.($flow['flow_type'] === 'outflow' ? '0' : '1').'|'.$flow['source'].'|'.str_pad((string) $flow['source_id'], 12, '0', STR_PAD_LEFT);
                })
                ->values();

            foreach ($datedFlows as $flow) {
                $running = round(
                    $running + ($flow['flow_type'] === 'inflow'
                        ? (float) $flow['forecast_amount']
                        : -(float) $flow['forecast_amount']),
                    2
                );

                if ($running < $lowestBalance) {
                    $lowestBalance = $running;
                    $lowestPeriod = $period['label'].' · '.$flow['date'];
                }
            }

            $period['opening_balance'] = $this->money($openingBalance);
            $period['inflows'] = $this->money($periodInflows);
            $period['outflows'] = $this->money($periodOutflows);
            $period['net_flow'] = $this->money($net);
            $period['closing_balance'] = $this->money($running);
            $period['receivables'] = $this->money($periodFlows->where('source', 'customer_installment')->sum('forecast_amount'));
            $period['payables'] = $this->money($periodFlows->where('source', 'vendor_bill')->sum('forecast_amount'));
            $period['manual_inflows'] = $this->money(
                $periodFlows->where('source', 'treasury_commitment')->where('flow_type', 'inflow')->sum('forecast_amount')
            );
            $period['manual_outflows'] = $this->money(
                $periodFlows->where('source', 'treasury_commitment')->where('flow_type', 'outflow')->sum('forecast_amount')
            );
            $period['flow_count'] = $periodFlows->count();
        }
        unset($period);

        $totalInflows = round((float) $flows->where('flow_type', 'inflow')->sum('forecast_amount'), 2);
        $totalOutflows = round((float) $flows->where('flow_type', 'outflow')->sum('forecast_amount'), 2);

        return response()->json([
            'snapshot_date' => $start->toDateString(),
            'through_date' => $end->toDateString(),
            'assumptions' => [
                'interval' => $interval,
                'horizon_days' => $horizonDays,
                'collection_percent' => $collectionPercent,
                'payable_percent' => $payablePercent,
                'use_commitment_probability' => $useProbability,
            ],
            'opening_liquidity' => [
                'total' => $this->money($opening['total']),
                'accounts' => $opening['accounts'],
            ],
            'summary' => [
                'opening_liquidity' => $this->money($opening['total']),
                'forecast_inflows' => $this->money($totalInflows),
                'forecast_outflows' => $this->money($totalOutflows),
                'net_change' => $this->money($totalInflows - $totalOutflows),
                'projected_closing_liquidity' => $this->money($opening['total'] + $totalInflows - $totalOutflows),
                'lowest_projected_balance' => $this->money($lowestBalance),
                'lowest_period' => $lowestPeriod,
                'funding_gap' => $this->money(max(0, -$lowestBalance)),
                'unscheduled_receivables' => $this->money($inflows['unscheduled']),
            ],
            'periods' => $periods,
            'flows' => $flows->map(function ($flow) {
                $flow['gross_amount'] = $this->money($flow['gross_amount']);
                $flow['forecast_amount'] = $this->money($flow['forecast_amount']);
                return $flow;
            })->values(),
            'notes' => [
                'Opening liquidity is based on posted journal balances of active bank/cash accounts at the snapshot date.',
                'Project filtering applies to forecast flows; opening liquidity remains the selected branch/account liquidity pool.',
                'Overdue receivables and payables are carried into the first forecast date.',
                'Unscheduled customer balances are disclosed separately because they have no reliable expected collection date.',
            ],
        ]);
    }

    private function openingLiquidity(Carbon $asOf, $branchId, $bankAccountId)
    {
        $accounts = BankAccount::with('chartOfAccount:id,code,name')
            ->where('is_active', true)
            ->when($branchId, function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->when($bankAccountId, function ($query) use ($bankAccountId) {
                $query->where('id', $bankAccountId);
            })
            ->orderBy('name')
            ->get();

        $ids = $accounts->pluck('chart_of_account_id');

        $balances = $ids->count()
            ? DB::table('journal_lines as l')
                ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->where('e.status', 'posted')
                ->whereDate('e.entry_date', '<=', $asOf->toDateString())
                ->whereIn('l.chart_of_account_id', $ids)
                ->groupBy('l.chart_of_account_id')
                ->select('l.chart_of_account_id')
                ->selectRaw('COALESCE(SUM(l.debit - l.credit), 0) as balance')
                ->pluck('balance', 'l.chart_of_account_id')
            : collect();

        $rows = $accounts->map(function ($account) use ($balances) {
            $balance = round((float) ($balances[$account->chart_of_account_id] ?? 0), 2);

            return [
                'id' => $account->id,
                'name' => $account->name,
                'bank_name' => $account->bank_name,
                'account_type' => $account->account_type,
                'currency' => $account->currency,
                'ledger_code' => optional($account->chartOfAccount)->code,
                'ledger_name' => optional($account->chartOfAccount)->name,
                'balance' => $this->money($balance),
            ];
        });

        return [
            'total' => round((float) $rows->sum(function ($row) {
                return (float) $row['balance'];
            }), 2),
            'accounts' => $rows->values(),
        ];
    }

    private function receivableFlows(Carbon $start, Carbon $end, $branchId, $projectId, $percent)
    {
        $query = Installment::with([
                'booking:id,customer_id,property_id,booking_number,status,remaining_amount',
                'booking.customer:id,name,customer_number',
                'booking.property:id,project_id,property_number',
                'booking.property.project:id,branch_id,name,code',
                'plan:id,status',
            ])
            ->where('remaining_amount', '>', 0)
            ->whereHas('plan', function ($plan) {
                $plan->where('status', 'active');
            })
            ->whereHas('booking', function ($booking) {
                $booking->whereNotIn('status', ['cancelled']);
            })
            ->when($branchId, function ($installment) use ($branchId) {
                $installment->whereHas('booking.property.project', function ($project) use ($branchId) {
                    $project->where('branch_id', $branchId);
                });
            })
            ->when($projectId, function ($installment) use ($projectId) {
                $installment->whereHas('booking.property', function ($property) use ($projectId) {
                    $property->where('project_id', $projectId);
                });
            })
            ->whereDate('due_date', '<=', $end->toDateString());

        $installments = $query->orderBy('due_date')->get();

        $flows = $installments->map(function ($installment) use ($start, $percent) {
            $gross = round((float) $installment->remaining_amount, 2);
            $forecast = round($gross * ($percent / 100), 2);
            $date = $installment->due_date->lt($start)
                ? $start->toDateString()
                : $installment->due_date->toDateString();
            $booking = $installment->booking;
            $project = optional(optional($booking)->property)->project;

            return [
                'date' => $date,
                'flow_type' => 'inflow',
                'source' => 'customer_installment',
                'source_id' => $installment->id,
                'reference' => optional($booking)->booking_number.' / Installment #'.$installment->installment_number,
                'counterparty' => optional(optional($booking)->customer)->name,
                'project_id' => optional($project)->id,
                'project_name' => optional($project)->name,
                'category' => 'Customer Collection',
                'gross_amount' => $gross,
                'forecast_amount' => $forecast,
                'probability_percent' => $percent,
                'overdue' => $installment->due_date->lt($start),
            ];
        });

        $scheduledQuery = Installment::query()
            ->where('remaining_amount', '>', 0)
            ->whereHas('plan', function ($plan) {
                $plan->where('status', 'active');
            })
            ->whereHas('booking', function ($booking) {
                $booking->whereNotIn('status', ['cancelled']);
            })
            ->when($branchId, function ($installment) use ($branchId) {
                $installment->whereHas('booking.property.project', function ($project) use ($branchId) {
                    $project->where('branch_id', $branchId);
                });
            })
            ->when($projectId, function ($installment) use ($projectId) {
                $installment->whereHas('booking.property', function ($property) use ($projectId) {
                    $property->where('project_id', $projectId);
                });
            })
            ->select('booking_id')
            ->selectRaw('SUM(remaining_amount) as scheduled_balance')
            ->groupBy('booking_id')
            ->pluck('scheduled_balance', 'booking_id');

        $scheduledByBooking = $scheduledQuery->map(function ($value) {
            return round((float) $value, 2);
        });

        $bookings = Booking::with('property.project:id,branch_id')
            ->where('remaining_amount', '>', 0)
            ->whereNotIn('status', ['cancelled'])
            ->when($branchId, function ($booking) use ($branchId) {
                $booking->whereHas('property.project', function ($project) use ($branchId) {
                    $project->where('branch_id', $branchId);
                });
            })
            ->when($projectId, function ($booking) use ($projectId) {
                $booking->whereHas('property', function ($property) use ($projectId) {
                    $property->where('project_id', $projectId);
                });
            })
            ->get(['id','property_id','remaining_amount']);

        $unscheduled = round((float) $bookings->sum(function ($booking) use ($scheduledByBooking) {
            return max(
                0,
                round((float) $booking->remaining_amount - (float) ($scheduledByBooking[$booking->id] ?? 0), 2)
            );
        }), 2);

        return ['flows' => $flows, 'unscheduled' => $unscheduled];
    }

    private function payableFlows(Carbon $start, Carbon $end, $branchId, $projectId, $percent)
    {
        $bills = VendorBill::with([
                'vendor:id,name,vendor_number',
                'project:id,name,code',
                'lines:id,vendor_bill_id,project_id,amount',
                'payments.allocations:id,vendor_bill_payment_id,project_id,amount',
            ])
            ->whereIn('status', ['posted','partial'])
            ->where('remaining_amount', '>', 0)
            ->when($branchId, function ($bill) use ($branchId) {
                $bill->where('branch_id', $branchId);
            })
            ->when($projectId, function ($bill) use ($projectId) {
                $bill->where(function ($query) use ($projectId) {
                    $query->where('project_id', $projectId)
                        ->orWhereHas('lines', function ($line) use ($projectId) {
                            $line->where('project_id', $projectId);
                        });
                });
            })
            ->whereDate('due_date', '<=', $end->toDateString())
            ->orderBy('due_date')
            ->get();

        return $bills->map(function ($bill) use ($start, $projectId, $percent) {
            $gross = $this->vendorBillBalanceForProject($bill, $projectId);

            if ($gross <= 0.009) return null;

            $forecast = round($gross * ($percent / 100), 2);
            $date = $bill->due_date->lt($start)
                ? $start->toDateString()
                : $bill->due_date->toDateString();

            return [
                'date' => $date,
                'flow_type' => 'outflow',
                'source' => 'vendor_bill',
                'source_id' => $bill->id,
                'reference' => $bill->bill_number.($bill->vendor_invoice_number ? ' / '.$bill->vendor_invoice_number : ''),
                'counterparty' => optional($bill->vendor)->name,
                'project_id' => $projectId ?: $bill->project_id,
                'project_name' => $projectId
                    ? optional(Project::find($projectId))->name
                    : optional($bill->project)->name,
                'category' => 'Vendor Payment',
                'gross_amount' => $gross,
                'forecast_amount' => $forecast,
                'probability_percent' => $percent,
                'overdue' => $bill->due_date->lt($start),
            ];
        })->filter()->values();
    }

    private function commitmentFlows(Carbon $start, Carbon $end, $branchId, $projectId, $bankAccountId, $useProbability)
    {
        return TreasuryCommitment::with(['project:id,name','bankAccount:id,name'])
            ->where('status', 'planned')
            ->when($branchId, function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->when($projectId, function ($query) use ($projectId) {
                $query->where('project_id', $projectId);
            })
            ->when($bankAccountId, function ($query) use ($bankAccountId) {
                $query->where(function ($commitment) use ($bankAccountId) {
                    $commitment->whereNull('bank_account_id')
                        ->orWhere('bank_account_id', $bankAccountId);
                });
            })
            ->whereDate('expected_date', '<=', $end->toDateString())
            ->orderBy('expected_date')
            ->get()
            ->map(function ($commitment) use ($start, $useProbability) {
                $gross = round((float) $commitment->amount, 2);
                $probability = $useProbability ? (int) $commitment->probability_percent : 100;
                $forecast = round($gross * ($probability / 100), 2);
                $date = $commitment->expected_date->lt($start)
                    ? $start->toDateString()
                    : $commitment->expected_date->toDateString();

                return [
                    'date' => $date,
                    'flow_type' => $commitment->flow_type,
                    'source' => 'treasury_commitment',
                    'source_id' => $commitment->id,
                    'reference' => 'Treasury #'.$commitment->id,
                    'counterparty' => $commitment->title,
                    'project_id' => $commitment->project_id,
                    'project_name' => optional($commitment->project)->name,
                    'category' => $commitment->category,
                    'gross_amount' => $gross,
                    'forecast_amount' => $forecast,
                    'probability_percent' => $probability,
                    'overdue' => $commitment->expected_date->lt($start),
                ];
            });
    }

    private function vendorBillBalanceForProject($bill, $projectId)
    {
        if (!$projectId) {
            return round((float) $bill->remaining_amount, 2);
        }

        $gross = round((float) $bill->lines->sum(function ($line) use ($bill, $projectId) {
            $effectiveProject = $line->project_id ?: $bill->project_id;
            return (int) $effectiveProject === (int) $projectId ? (float) $line->amount : 0.0;
        }), 2);

        $paid = round((float) $bill->payments->sum(function ($payment) use ($bill, $projectId) {
            if ($payment->reversed_at) return 0.0;

            if ($payment->allocations->count()) {
                return (float) $payment->allocations->sum(function ($allocation) use ($projectId) {
                    return (int) $allocation->project_id === (int) $projectId
                        ? (float) $allocation->amount
                        : 0.0;
                });
            }

            return (int) $bill->project_id === (int) $projectId
                ? (float) $payment->amount
                : 0.0;
        }), 2);

        return max(0, round($gross - $paid, 2));
    }

    private function periods(Carbon $start, Carbon $end, $interval)
    {
        $periods = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            if ($interval === 'monthly') {
                $periodEnd = $cursor->copy()->endOfMonth();
            } else {
                $periodEnd = $cursor->copy()->addDays(6);
            }

            if ($periodEnd->gt($end)) $periodEnd = $end->copy();

            $periods[] = [
                'label' => $interval === 'monthly'
                    ? $cursor->format('M Y')
                    : $cursor->format('d M').' – '.$periodEnd->format('d M'),
                'from' => $cursor->toDateString(),
                'to' => $periodEnd->toDateString(),
            ];

            $cursor = $periodEnd->copy()->addDay();
        }

        return $periods;
    }

    private function validateCommitment(Request $request, $partial = false)
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'branch_id' => [$partial ? 'sometimes' : 'nullable','nullable','integer','exists:branches,id'],
            'project_id' => ['nullable','integer','exists:projects,id'],
            'bank_account_id' => ['nullable','integer','exists:bank_accounts,id'],
            'flow_type' => [$required,'in:inflow,outflow'],
            'category' => [$required,'string','max:100'],
            'title' => [$required,'string','max:255'],
            'expected_date' => [$required,'date'],
            'amount' => [$required,'numeric','min:0.01'],
            'probability_percent' => ['nullable','integer','min:0','max:100'],
            'notes' => ['nullable','string','max:5000'],
        ]);
    }

    private function validateCommitmentDimensions(array $data)
    {
        $branchId = (int) $data['branch_id'];

        if (!empty($data['project_id'])) {
            $project = Project::where('is_active', true)->findOrFail($data['project_id']);
            if ((int) $project->branch_id !== $branchId) {
                throw ValidationException::withMessages([
                    'project_id' => ['The project must belong to the selected branch.'],
                ]);
            }
        }

        if (!empty($data['bank_account_id'])) {
            $account = BankAccount::where('is_active', true)->findOrFail($data['bank_account_id']);

            if ($account->branch_id && (int) $account->branch_id !== $branchId) {
                throw ValidationException::withMessages([
                    'bank_account_id' => ['The bank/cash account must belong to the selected branch.'],
                ]);
            }
        }
    }

    private function validateForecastDimensions($branchId, $projectId, $bankAccountId)
    {
        if ($projectId) {
            $project = Project::where('is_active', true)->findOrFail($projectId);
            if ($branchId && (int) $project->branch_id !== (int) $branchId) {
                abort(422, 'The selected project does not belong to the selected branch.');
            }
        }

        if ($bankAccountId) {
            $account = BankAccount::where('is_active', true)->findOrFail($bankAccountId);
            if ($branchId && $account->branch_id && (int) $account->branch_id !== (int) $branchId) {
                abort(422, 'The selected bank/cash account does not belong to the selected branch.');
            }
        }
    }

    private function commitmentScope($query, Request $request)
    {
        $branchId = $this->resolveForecastBranch($request->branch_id);

        if ($branchId) $query->where('branch_id', $branchId);
        if ($request->filled('project_id')) $query->where('project_id', (int) $request->project_id);

        return $query;
    }

    private function accessibleCommitment($id)
    {
        $query = TreasuryCommitment::query();
        $branchId = $this->userBranchId();

        if ($branchId) $query->where('branch_id', $branchId);

        return $query->findOrFail($id);
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

    private function resolveForecastBranch($branchId)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;

            if (!$branchId) abort(403, 'Your user account is not assigned to a branch.');

            return (int) $branchId;
        }

        return $branchId ? (int) $branchId : null;
    }

    private function userBranchId()
    {
        if ($this->canAccessAllBranches()) return null;

        $branchId = auth()->user()->branch_id;
        if (!$branchId) abort(403, 'Your user account is not assigned to a branch.');

        return (int) $branchId;
    }

    private function money($value)
    {
        return number_format((float) $value, 2, '.', '');
    }
}
