<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AccountsReceivableController extends Controller
{
    use ChecksBranchAccess;

    private function validatedFilters(Request $request)
    {
        return $request->validate([
            'as_of' => ['nullable','date'],
            'branch_id' => ['nullable','integer','exists:branches,id'],
            'project_id' => ['nullable','integer','exists:projects,id'],
            'customer_id' => ['nullable','integer','exists:customers,id'],
            'search' => ['nullable','string','max:150'],
            'bucket' => ['nullable','in:current,1_30,31_60,61_90,90_plus,overdue'],
            'page' => ['nullable','integer','min:1'],
            'per_page' => ['nullable','integer','min:1','max:100'],
        ]);
    }

    private function selectedBranchId(array $filters)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;

            if (!$branchId) {
                abort(403, 'Your user account is not assigned to a branch.');
            }

            return (int) $branchId;
        }

        return !empty($filters['branch_id']) ? (int) $filters['branch_id'] : null;
    }

    private function bookingsQuery(array $filters)
    {
        $branchId = $this->selectedBranchId($filters);

        $query = Booking::query()
            ->with([
                'customer:id,customer_number,branch_id,name,phone,email',
                'property:id,project_id,property_number',
                'property.project:id,branch_id,name,code',
                'installments' => function ($installments) {
                    $installments
                        ->where('remaining_amount', '>', 0)
                        ->whereHas('plan', function ($plan) {
                            $plan->where('status', 'active');
                        })
                        ->orderBy('due_date')
                        ->orderBy('id');
                },
            ])
            ->where('remaining_amount', '>', 0)
            ->whereNotIn('status', ['cancelled']);

        if ($branchId) {
            $query->whereHas('property.project', function ($project) use ($branchId) {
                $project->where('branch_id', $branchId);
            });
        }

        if (!empty($filters['project_id'])) {
            $query->whereHas('property', function ($property) use ($filters) {
                $property->where('project_id', (int) $filters['project_id']);
            });
        }

        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);

            $query->where(function ($q) use ($search) {
                $q->where('booking_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customer) use ($search) {
                        $customer->where('name', 'like', "%{$search}%")
                            ->orWhere('customer_number', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    })
                    ->orWhereHas('property', function ($property) use ($search) {
                        $property->where('property_number', 'like', "%{$search}%")
                            ->orWhereHas('project', function ($project) use ($search) {
                                $project->where('name', 'like', "%{$search}%")
                                    ->orWhere('code', 'like', "%{$search}%");
                            });
                    });
            });
        }

        return $query;
    }

    public function options(Request $request)
    {
        $filters = $request->validate([
            'branch_id' => ['nullable','integer','exists:branches,id'],
        ]);

        $branchId = $this->selectedBranchId($filters);

        $projects = Project::query()
            ->where('is_active', true)
            ->when($branchId, function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->orderBy('name')
            ->get(['id','branch_id','name','code']);

        $customers = Customer::query()
            ->where('is_active', true)
            ->when($branchId, function ($query) use ($branchId) {
                $query->where(function ($customer) use ($branchId) {
                    $customer->where('branch_id', $branchId)
                        ->orWhereHas('bookings.property.project', function ($project) use ($branchId) {
                            $project->where('branch_id', $branchId);
                        });
                });
            })
            ->whereHas('bookings', function ($booking) use ($branchId) {
                $booking->where('remaining_amount', '>', 0)
                    ->whereNotIn('status', ['cancelled']);

                if ($branchId) {
                    $booking->whereHas('property.project', function ($project) use ($branchId) {
                        $project->where('branch_id', $branchId);
                    });
                }
            })
            ->orderBy('name')
            ->get(['id','branch_id','customer_number','name','phone']);

        $branches = [];

        if ($this->canAccessAllBranches()) {
            $branches = \App\Models\Branch::where('is_active', true)
                ->orderBy('name')
                ->get(['id','name','code']);
        }

        return response()->json([
            'projects' => $projects,
            'customers' => $customers,
            'branches' => $branches,
            'buckets' => [
                ['value' => 'current', 'text' => 'Current'],
                ['value' => '1_30', 'text' => '1–30 Days'],
                ['value' => '31_60', 'text' => '31–60 Days'],
                ['value' => '61_90', 'text' => '61–90 Days'],
                ['value' => '90_plus', 'text' => '90+ Days'],
                ['value' => 'overdue', 'text' => 'All Overdue'],
            ],
        ]);
    }

    public function aging(Request $request)
    {
        $filters = $this->validatedFilters($request);
        $asOf = !empty($filters['as_of'])
            ? Carbon::parse($filters['as_of'])->startOfDay()
            : now()->startOfDay();

        $bookings = $this->bookingsQuery($filters)
            ->orderBy('customer_id')
            ->orderBy('booking_date')
            ->orderBy('id')
            ->get();

        $bookingRows = $bookings->map(function ($booking) use ($asOf) {
            return $this->bookingAging($booking, $asOf);
        });

        $customerRows = $this->customerRows($bookingRows);
        $projectRows = $this->projectRows($bookingRows);
        $summary = $this->summary($bookingRows, $customerRows);

        if (!empty($filters['bucket'])) {
            $bucket = $filters['bucket'];

            $customerRows = $customerRows->filter(function ($row) use ($bucket) {
                if ($bucket === 'overdue') {
                    return (float) $row['overdue'] > 0;
                }

                return (float) $row[$bucket] > 0;
            })->values();
        }

        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);
        $total = $customerRows->count();
        $pageRows = $customerRows->slice(($page - 1) * $perPage, $perPage)->values();

        $customers = new LengthAwarePaginator(
            $pageRows,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json([
            'as_of' => $asOf->toDateString(),
            'summary' => $summary,
            'customers' => $customers,
            'projects' => $projectRows->values(),
        ]);
    }

    public function customer(Request $request, Customer $customer)
    {
        $filters = $request->validate([
            'as_of' => ['nullable','date'],
            'branch_id' => ['nullable','integer','exists:branches,id'],
            'project_id' => ['nullable','integer','exists:projects,id'],
        ]);

        $filters['customer_id'] = $customer->id;
        $asOf = !empty($filters['as_of'])
            ? Carbon::parse($filters['as_of'])->startOfDay()
            : now()->startOfDay();

        $bookings = $this->bookingsQuery($filters)
            ->orderBy('booking_date')
            ->orderBy('id')
            ->get();

        if (!$bookings->count()) {
            $this->selectedBranchId($filters);
        }

        $rows = $bookings->map(function ($booking) use ($asOf) {
            return $this->bookingAging($booking, $asOf, true);
        });

        return response()->json([
            'as_of' => $asOf->toDateString(),
            'customer' => $customer->only([
                'id','customer_number','branch_id','name','phone','email','address','city'
            ]),
            'summary' => $this->sumRows($rows),
            'bookings' => $rows->values(),
        ]);
    }

    private function bookingAging($booking, Carbon $asOf, $includeInstallments = false)
    {
        $outstanding = round((float) $booking->remaining_amount, 2);
        $remainingToAllocate = $outstanding;
        $buckets = $this->emptyBuckets();
        $installmentRows = [];
        $oldestDueDate = null;
        $nextDueDate = null;
        $maxDaysOverdue = 0;
        $overdueInstallments = 0;

        foreach ($booking->installments as $installment) {
            if ($remainingToAllocate <= 0) {
                break;
            }

            $raw = round((float) $installment->remaining_amount, 2);

            if ($raw <= 0) {
                continue;
            }

            $amount = min($raw, $remainingToAllocate);
            $remainingToAllocate = round($remainingToAllocate - $amount, 2);
            $dueDate = Carbon::parse($installment->due_date)->startOfDay();
            $bucket = $this->bucketForDate($dueDate, $asOf);
            $buckets[$bucket] = round($buckets[$bucket] + $amount, 2);

            $daysOverdue = $dueDate->lt($asOf) ? $dueDate->diffInDays($asOf) : 0;

            if ($daysOverdue > 0) {
                $overdueInstallments++;
                $maxDaysOverdue = max($maxDaysOverdue, $daysOverdue);

                if (!$oldestDueDate || $dueDate->lt(Carbon::parse($oldestDueDate))) {
                    $oldestDueDate = $dueDate->toDateString();
                }
            } elseif (!$nextDueDate || $dueDate->lt(Carbon::parse($nextDueDate))) {
                $nextDueDate = $dueDate->toDateString();
            }

            if ($includeInstallments) {
                $installmentRows[] = [
                    'id' => $installment->id,
                    'installment_number' => $installment->installment_number,
                    'due_date' => $dueDate->toDateString(),
                    'amount' => $this->money($installment->amount),
                    'paid_amount' => $this->money($installment->paid_amount),
                    'remaining_amount' => $this->money($amount),
                    'status' => $installment->status,
                    'aging_bucket' => $bucket,
                    'days_overdue' => $daysOverdue,
                ];
            }
        }

        $unallocated = max(0, round($remainingToAllocate, 2));
        $buckets['current'] = round($buckets['current'] + $unallocated, 2);
        $overdue = round(
            $buckets['1_30'] +
            $buckets['31_60'] +
            $buckets['61_90'] +
            $buckets['90_plus'],
            2
        );

        $row = [
            'booking_id' => $booking->id,
            'booking_number' => $booking->booking_number,
            'booking_date' => optional($booking->booking_date)->toDateString(),
            'booking_status' => $booking->status,
            'customer_id' => $booking->customer_id,
            'customer_number' => optional($booking->customer)->customer_number,
            'customer_name' => optional($booking->customer)->name,
            'customer_phone' => optional($booking->customer)->phone,
            'project_id' => optional(optional($booking->property)->project)->id,
            'project_code' => optional(optional($booking->property)->project)->code,
            'project_name' => optional(optional($booking->property)->project)->name,
            'branch_id' => optional(optional($booking->property)->project)->branch_id,
            'property_id' => $booking->property_id,
            'property_number' => optional($booking->property)->property_number,
            'contract_value' => $this->money($booking->final_price),
            'paid_amount' => $this->money($booking->paid_amount),
            'total' => $this->money($outstanding),
            'current' => $this->money($buckets['current']),
            '1_30' => $this->money($buckets['1_30']),
            '31_60' => $this->money($buckets['31_60']),
            '61_90' => $this->money($buckets['61_90']),
            '90_plus' => $this->money($buckets['90_plus']),
            'overdue' => $this->money($overdue),
            'unallocated' => $this->money($unallocated),
            'overdue_installments' => $overdueInstallments,
            'oldest_due_date' => $oldestDueDate,
            'next_due_date' => $nextDueDate,
            'max_days_overdue' => $maxDaysOverdue,
        ];

        if ($includeInstallments) {
            $row['installments'] = $installmentRows;
        }

        return $row;
    }

    private function customerRows(Collection $bookingRows)
    {
        return $bookingRows
            ->groupBy('customer_id')
            ->map(function ($rows) {
                $first = $rows->first();
                $sum = $this->sumRows($rows);
                $contractValue = round($rows->sum(function ($row) {
                    return (float) $row['contract_value'];
                }), 2);
                $paid = round($rows->sum(function ($row) {
                    return (float) $row['paid_amount'];
                }), 2);
                $projects = $rows->pluck('project_id')->filter()->unique()->values();
                $oldest = $rows->pluck('oldest_due_date')->filter()->sort()->first();

                return array_merge($sum, [
                    'customer_id' => $first['customer_id'],
                    'customer_number' => $first['customer_number'],
                    'customer_name' => $first['customer_name'],
                    'customer_phone' => $first['customer_phone'],
                    'booking_count' => $rows->count(),
                    'project_count' => $projects->count(),
                    'contract_value' => $this->money($contractValue),
                    'paid_amount' => $this->money($paid),
                    'collection_rate' => $contractValue > 0
                        ? round(($paid / $contractValue) * 100, 1)
                        : 0,
                    'oldest_due_date' => $oldest,
                    'max_days_overdue' => (int) $rows->max('max_days_overdue'),
                    'overdue_installments' => (int) $rows->sum('overdue_installments'),
                ]);
            })
            ->sort(function ($a, $b) {
                if ((float) $a['overdue'] === (float) $b['overdue']) {
                    return (float) $b['total'] <=> (float) $a['total'];
                }

                return (float) $b['overdue'] <=> (float) $a['overdue'];
            })
            ->values();
    }

    private function projectRows(Collection $bookingRows)
    {
        return $bookingRows
            ->filter(function ($row) {
                return !empty($row['project_id']);
            })
            ->groupBy('project_id')
            ->map(function ($rows) {
                $first = $rows->first();
                $sum = $this->sumRows($rows);

                return array_merge($sum, [
                    'project_id' => $first['project_id'],
                    'project_code' => $first['project_code'],
                    'project_name' => $first['project_name'],
                    'branch_id' => $first['branch_id'],
                    'customer_count' => $rows->pluck('customer_id')->filter()->unique()->count(),
                    'booking_count' => $rows->count(),
                    'max_days_overdue' => (int) $rows->max('max_days_overdue'),
                ]);
            })
            ->sortByDesc(function ($row) {
                return (float) $row['total'];
            })
            ->values();
    }

    private function summary(Collection $bookingRows, Collection $customerRows)
    {
        $sum = $this->sumRows($bookingRows);
        $total = (float) $sum['total'];
        $overdue = (float) $sum['overdue'];

        return array_merge($sum, [
            'customers_with_balance' => $customerRows->count(),
            'overdue_customers' => $customerRows->filter(function ($row) {
                return (float) $row['overdue'] > 0;
            })->count(),
            'bookings_with_balance' => $bookingRows->count(),
            'overdue_installments' => (int) $bookingRows->sum('overdue_installments'),
            'overdue_percentage' => $total > 0 ? round(($overdue / $total) * 100, 1) : 0,
        ]);
    }

    private function sumRows(Collection $rows)
    {
        $fields = ['total','current','1_30','31_60','61_90','90_plus','overdue','unallocated'];
        $result = [];

        foreach ($fields as $field) {
            $result[$field] = $this->money($rows->sum(function ($row) use ($field) {
                return (float) ($row[$field] ?? 0);
            }));
        }

        return $result;
    }

    private function bucketForDate(Carbon $dueDate, Carbon $asOf)
    {
        if ($dueDate->gte($asOf)) {
            return 'current';
        }

        $days = $dueDate->diffInDays($asOf);

        if ($days <= 30) {
            return '1_30';
        }

        if ($days <= 60) {
            return '31_60';
        }

        if ($days <= 90) {
            return '61_90';
        }

        return '90_plus';
    }

    private function emptyBuckets()
    {
        return [
            'current' => 0.0,
            '1_30' => 0.0,
            '31_60' => 0.0,
            '61_90' => 0.0,
            '90_plus' => 0.0,
        ];
    }

    private function money($value)
    {
        return number_format((float) $value, 2, '.', '');
    }
}
