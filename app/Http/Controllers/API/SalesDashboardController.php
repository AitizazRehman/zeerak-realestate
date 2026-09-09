<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Customer;
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
    public function index(Request $request)
    {
        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : now()->startOfMonth()->subMonths(5)->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();
        $sales = Booking::whereIn('status', ['confirmed', 'completed']);
        $payments = Payment::where('status', 'verified')->whereBetween('payment_date', [$from, $to]);
        $monthlyCollections = Payment::selectRaw("DATE_FORMAT(payment_date, '%Y-%m') as month, SUM(amount) as amount")->where('status', 'verified')->whereBetween('payment_date', [$from, $to])->groupBy(DB::raw("DATE_FORMAT(payment_date, '%Y-%m')"))->orderBy('month')->get();
        $agentPerformance = Booking::query()->select('sales_agent_id', DB::raw('COUNT(*) as bookings'), DB::raw('SUM(final_price) as sales_value'), DB::raw('SUM(paid_amount) as collected'))->with('salesAgent:id,name')->whereIn('status', ['confirmed', 'completed'])->whereBetween('booking_date', [$from->toDateString(), $to->toDateString()])->whereNotNull('sales_agent_id')->groupBy('sales_agent_id')->orderByDesc('sales_value')->limit(10)->get();
        $projectPerformance = Property::query()->select('properties.project_id', 'projects.name as project_name', DB::raw('COUNT(bookings.id) as bookings'), DB::raw('COALESCE(SUM(bookings.final_price),0) as sales_value'), DB::raw('COALESCE(SUM(bookings.paid_amount),0) as collected'))->join('projects', 'projects.id', '=', 'properties.project_id')->leftJoin('bookings', function ($join) use ($from, $to) { $join->on('bookings.property_id', '=', 'properties.id')->whereIn('bookings.status', ['confirmed', 'completed'])->whereBetween('bookings.booking_date', [$from->toDateString(), $to->toDateString()]); })->groupBy('properties.project_id', 'projects.name')->orderByDesc('sales_value')->limit(10)->get();
        $overdue = Installment::whereIn('status', ['pending', 'partial', 'overdue'])->where('due_date', '<', now()->toDateString())->where('remaining_amount', '>', 0);
        $receivables = Booking::whereNotIn('status', ['cancelled']);
        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'metrics' => [
                'customers' => Customer::where('is_active', true)->count(), 'active_leads' => Lead::whereNotIn('status', ['converted', 'lost'])->count(),
                'scheduled_visits' => SiteVisit::where('status', 'scheduled')->where('visit_at', '>=', now())->count(),
                'available_properties' => Property::where('status', 'available')->count(), 'reserved_properties' => Property::where('status', 'reserved')->count(), 'booked_properties' => Property::where('status', 'booked')->count(), 'sold_properties' => Property::where('status', 'sold')->count(),
                'sales_value' => (float) $sales->whereBetween('booking_date', [$from->toDateString(), $to->toDateString()])->sum('final_price'), 'collections' => (float) $payments->sum('amount'), 'receivables' => (float) $receivables->sum('remaining_amount'), 'overdue_count' => (int) $overdue->count(), 'overdue_amount' => (float) $overdue->sum('remaining_amount'),
            ], 'monthly_collections' => $monthlyCollections, 'agent_performance' => $agentPerformance, 'project_performance' => $projectPerformance,
        ]);
    }

    public function receivables(Request $request)
    {
        $q = Installment::with(['booking.customer','booking.property','booking.salesAgent'])->where('remaining_amount','>',0)->whereIn('status',['pending','partial','overdue']);
        if ($request->boolean('overdue')) $q->where('due_date','<',now()->toDateString());
        if ($request->filled('booking_id')) $q->where('booking_id',(int)$request->booking_id);
        $perPage = min(max((int)$request->get('per_page',25),1),100);
        return response()->json($q->orderBy('due_date')->paginate($perPage));
    }
}
