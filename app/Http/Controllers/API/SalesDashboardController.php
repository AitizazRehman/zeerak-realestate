<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Installment;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Property;
use App\Models\SiteVisit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesDashboardController extends Controller
{
    use ChecksBranchAccess;
    private function branch($q,$relation='property.project'){if(!$this->canAccessAllBranches())$q->whereHas($relation,function($x){$x->where('branch_id',auth()->user()->branch_id);});return $q;}
    private function branchExpenses($q)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;
            $q->where(function ($x) use ($branchId) {
                $x->where('branch_id', $branchId)
                  ->orWhereHas('project', function ($project) use ($branchId) {
                    $project->where('branch_id', $branchId);
                })->orWhere(function ($legacy) use ($branchId) {
                    $legacy->whereNull('project_id')->whereHas('property.project', function ($project) use ($branchId) {
                        $project->where('branch_id', $branchId);
                    });
                });
            });
        }
        return $q;
    }
    private function branchCustomers($q)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;
            $q->where(function ($customer) use ($branchId) {
                $customer->where('branch_id', $branchId)
                    ->orWhereHas('bookings.property.project', function ($project) use ($branchId) {
                        $project->where('branch_id', $branchId);
                    })
                    ->orWhereHas('leads', function ($lead) use ($branchId) {
                        $lead->whereHas('project', function ($project) use ($branchId) {
                            $project->where('branch_id', $branchId);
                        })->orWhere(function ($fallback) use ($branchId) {
                            $fallback->whereNull('project_id')
                                ->whereHas('assignee', function ($agent) use ($branchId) {
                                    $agent->where('branch_id', $branchId);
                                });
                        });
                    });
            });
        }

        return $q;
    }

    private function branchLeads($q)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;
            $q->where(function ($x) use ($branchId) {
                $x->whereHas('project', function ($project) use ($branchId) {
                    $project->where('branch_id', $branchId);
                })->orWhere(function ($fallback) use ($branchId) {
                    $fallback->whereNull('project_id')->whereHas('assignee', function ($agent) use ($branchId) {
                        $agent->where('branch_id', $branchId);
                    });
                });
            });
        }
        return $q;
    }

    private function branchSiteVisits($q)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;
            $q->where(function ($x) use ($branchId) {
                $x->whereHas('property.project', function ($project) use ($branchId) {
                    $project->where('branch_id', $branchId);
                })->orWhere(function ($viaLead) use ($branchId) {
                    $viaLead->whereNull('property_id')->whereHas('lead.project', function ($project) use ($branchId) {
                        $project->where('branch_id', $branchId);
                    });
                })->orWhere(function ($viaLeadAgent) use ($branchId) {
                    $viaLeadAgent->whereNull('property_id')->whereHas('lead', function ($lead) use ($branchId) {
                        $lead->whereNull('project_id')->whereHas('assignee', function ($agent) use ($branchId) {
                            $agent->where('branch_id', $branchId);
                        });
                    });
                })->orWhere(function ($viaVisitAgent) use ($branchId) {
                    $viaVisitAgent->whereNull('property_id')->whereNull('lead_id')->whereHas('assignee', function ($agent) use ($branchId) {
                        $agent->where('branch_id', $branchId);
                    });
                });
            });
        }
        return $q;
    }

    private function percentChange($current, $previous)
    {
        $current = (float) $current;
        $previous = (float) $previous;

        if (abs($previous) < 0.01) {
            return abs($current) < 0.01 ? 0 : 100;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }

    public function index(Request $request)
    {
        $from = $request->filled('from')
            ? Carbon::parse($request->from)->startOfDay()
            : now()->startOfMonth()->subMonths(5)->startOfMonth();

        $to = $request->filled('to')
            ? Carbon::parse($request->to)->endOfDay()
            : now()->endOfDay();

        if ($from->gt($to)) {
            abort(422, 'The From date cannot be later than the To date.');
        }

        $periodDays = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $previousTo = $from->copy()->subDay()->endOfDay();
        $previousFrom = $previousTo->copy()->subDays($periodDays - 1)->startOfDay();

        $sales = $this->branch(Booking::query())
            ->whereIn('status', ['confirmed', 'completed']);

        $payments = $this->branch(Payment::query(), 'booking.property.project')
            ->where('status', 'verified')
            ->whereBetween('payment_date', [$from, $to]);

        $expenses = $this->branchExpenses(Expense::query())
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()]);

        $currentSalesValue = (float) (clone $sales)
            ->whereBetween('booking_date', [$from->toDateString(), $to->toDateString()])
            ->sum('final_price');

        $currentCollections = (float) (clone $payments)->sum('amount');
        $currentExpenses = (float) (clone $expenses)->sum('amount');
        $currentNetCashFlow = $currentCollections - $currentExpenses;

        $previousSalesValue = (float) $this->branch(Booking::query())
            ->whereIn('status', ['confirmed', 'completed'])
            ->whereBetween('booking_date', [$previousFrom->toDateString(), $previousTo->toDateString()])
            ->sum('final_price');

        $previousCollections = (float) $this->branch(Payment::query(), 'booking.property.project')
            ->where('status', 'verified')
            ->whereBetween('payment_date', [$previousFrom, $previousTo])
            ->sum('amount');

        $previousExpenses = (float) $this->branchExpenses(Expense::query())
            ->whereBetween('expense_date', [$previousFrom->toDateString(), $previousTo->toDateString()])
            ->sum('amount');

        $previousNetCashFlow = $previousCollections - $previousExpenses;

        $monthlySales = $this->branch(
            Booking::selectRaw("DATE_FORMAT(booking_date, '%Y-%m') as month, SUM(final_price) as amount")
        )
            ->whereIn('status', ['confirmed', 'completed'])
            ->whereBetween('booking_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy(DB::raw("DATE_FORMAT(booking_date, '%Y-%m')"))
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        $monthlyCollections = $this->branch(
            Payment::selectRaw("DATE_FORMAT(payment_date, '%Y-%m') as month, SUM(amount) as amount"),
            'booking.property.project'
        )
            ->where('status', 'verified')
            ->whereBetween('payment_date', [$from, $to])
            ->groupBy(DB::raw("DATE_FORMAT(payment_date, '%Y-%m')"))
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        $monthlyExpenses = $this->branchExpenses(
            Expense::selectRaw("DATE_FORMAT(expense_date, '%Y-%m') as month, SUM(amount) as amount")
        )
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy(DB::raw("DATE_FORMAT(expense_date, '%Y-%m')"))
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        $monthlyFinancials = [];
        $cursor = $from->copy()->startOfMonth();
        $lastMonth = $to->copy()->startOfMonth();

        while ($cursor->lte($lastMonth)) {
            $month = $cursor->format('Y-m');
            $salesAmount = isset($monthlySales[$month]) ? (float) $monthlySales[$month]->amount : 0;
            $collectionAmount = isset($monthlyCollections[$month]) ? (float) $monthlyCollections[$month]->amount : 0;
            $expenseAmount = isset($monthlyExpenses[$month]) ? (float) $monthlyExpenses[$month]->amount : 0;

            $monthlyFinancials[] = [
                'month' => $month,
                'sales' => $salesAmount,
                'collections' => $collectionAmount,
                'expenses' => $expenseAmount,
                'net_cash_flow' => $collectionAmount - $expenseAmount,
            ];

            $cursor->addMonth();
        }

        $agentPerformance = $this->branch(Booking::query())
            ->select(
                'sales_agent_id',
                DB::raw('COUNT(*) as bookings'),
                DB::raw('SUM(final_price) as sales_value'),
                DB::raw('SUM(paid_amount) as collected')
            )
            ->with('salesAgent:id,name')
            ->whereIn('status', ['confirmed', 'completed'])
            ->whereBetween('booking_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('sales_agent_id')
            ->groupBy('sales_agent_id')
            ->orderByDesc('sales_value')
            ->limit(10)
            ->get();

        $projectPerformance = $this->branch(Property::query(), 'project')
            ->select(
                'properties.project_id',
                'projects.name as project_name',
                DB::raw('COUNT(bookings.id) as bookings'),
                DB::raw('COALESCE(SUM(bookings.final_price),0) as sales_value'),
                DB::raw('COALESCE(SUM(bookings.paid_amount),0) as collected')
            )
            ->join('projects', 'projects.id', '=', 'properties.project_id')
            ->leftJoin('bookings', function ($join) use ($from, $to) {
                $join->on('bookings.property_id', '=', 'properties.id')
                    ->whereIn('bookings.status', ['confirmed', 'completed'])
                    ->whereBetween('bookings.booking_date', [$from->toDateString(), $to->toDateString()]);
            })
            ->groupBy('properties.project_id', 'projects.name')
            ->orderByDesc('sales_value')
            ->limit(10)
            ->get();

        $leadPipeline = $this->branchLeads(
            Lead::select('status', DB::raw('COUNT(*) as total'))
        )
            ->groupBy('status')
            ->get();

        $overdue = $this->branch(Installment::query(), 'booking.property.project')
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->where('due_date', '<', now()->toDateString())
            ->where('remaining_amount', '>', 0);

        $receivables = $this->branch(Booking::query())
            ->whereNotIn('status', ['cancelled']);

        $recentBookings = $this->branch(
            Booking::with(['customer:id,name', 'property:id,property_number'])
        )
            ->whereIn('status', ['confirmed', 'completed'])
            ->orderByDesc('booking_date')
            ->limit(5)
            ->get();

        $recentPayments = $this->branch(
            Payment::with(['customer:id,name', 'booking:id,booking_number']),
            'booking.property.project'
        )
            ->where('status', 'verified')
            ->orderByDesc('payment_date')
            ->limit(5)
            ->get();

        return response()->json([
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'previous_from' => $previousFrom->toDateString(),
                'previous_to' => $previousTo->toDateString(),
            ],
            'metrics' => [
                'customers' => $this->branchCustomers(Customer::where('is_active', true))->count(),
                'active_leads' => $this->branchLeads(Lead::query())
                    ->whereNotIn('status', ['converted', 'lost'])
                    ->count(),
                'scheduled_visits' => $this->branchSiteVisits(SiteVisit::query())
                    ->where('status', 'scheduled')
                    ->where('visit_at', '>=', now())
                    ->count(),
                'available_properties' => $this->branch(Property::query(), 'project')
                    ->where('status', 'available')
                    ->count(),
                'reserved_properties' => $this->branch(Property::query(), 'project')
                    ->where('status', 'reserved')
                    ->count(),
                'booked_properties' => $this->branch(Property::query(), 'project')
                    ->where('status', 'booked')
                    ->count(),
                'sold_properties' => $this->branch(Property::query(), 'project')
                    ->where('status', 'sold')
                    ->count(),
                'sales_value' => $currentSalesValue,
                'collections' => $currentCollections,
                'receivables' => (float) (clone $receivables)->sum('remaining_amount'),
                'overdue_count' => (int) (clone $overdue)->count(),
                'overdue_amount' => (float) (clone $overdue)->sum('remaining_amount'),
                'expenses' => $currentExpenses,
                'net_cash_flow' => $currentNetCashFlow,
            ],
            'trends' => [
                'sales_value' => $this->percentChange($currentSalesValue, $previousSalesValue),
                'collections' => $this->percentChange($currentCollections, $previousCollections),
                'expenses' => $this->percentChange($currentExpenses, $previousExpenses),
                'net_cash_flow' => $this->percentChange($currentNetCashFlow, $previousNetCashFlow),
            ],
            'monthly_collections' => $monthlyCollections->values(),
            'monthly_financials' => $monthlyFinancials,
            'lead_pipeline' => $leadPipeline,
            'agent_performance' => $agentPerformance,
            'project_performance' => $projectPerformance,
            'recent_bookings' => $recentBookings,
            'recent_payments' => $recentPayments,
        ]);
    }

    public function receivables(Request $request)
    {
        $q = $this->branch(Installment::with(['booking.customer','booking.property','booking.salesAgent']),'booking.property.project')->where('remaining_amount','>',0)->whereIn('status',['pending','partial','overdue']);
        if ($request->boolean('overdue')) $q->where('due_date','<',now()->toDateString());
        if ($request->filled('booking_id')) $q->where('booking_id',(int)$request->booking_id);
        $perPage = min(max((int)$request->get('per_page',25),1),100);
        return response()->json($q->orderBy('due_date')->paginate($perPage));
    }
}
