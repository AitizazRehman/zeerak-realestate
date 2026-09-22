<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Booking;
use App\Models\Installment;
use App\Models\InstallmentPlan;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InstallmentPlanController extends Controller
{
    use ChecksBranchAccess;

    private function scopeBranch($query)
    {
        if (!$this->canAccessAllBranches()) {
            $query->whereHas('booking.property.project', function ($q) {
                $q->where('branch_id', auth()->user()->branch_id);
            });
        }
        return $query;
    }
    public function index(Request $request)
    {
        $query = $this->scopeBranch(InstallmentPlan::with(['booking.customer', 'booking.property']));
        if ($request->filled('booking_id')) $query->where('booking_id', $request->booking_id);
        if ($request->filled('status')) $query->where('status', $request->status);
        return response()->json($query->latest()->paginate(min(max((int) $request->get('per_page', 15), 1), 100)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'plan_name' => 'required|string|max:100',
            'frequency' => 'required|in:monthly,quarterly,half_yearly,yearly,custom',
            'total_amount' => 'required|numeric|min:0.01',
            'down_payment' => 'nullable|numeric|min:0',
            'installment_amount' => 'required|numeric|min:0.01',
            'number_of_installments' => 'required|integer|min:1',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'nullable|in:active,completed,cancelled',
            'notes' => 'nullable|string',
        ]);

        $plan = DB::transaction(function () use ($data) {
            $booking = Booking::with('property.project')->lockForUpdate()->findOrFail($data['booking_id']);
            $this->ensureBranchAccess($booking->property->project->branch_id);
            if (in_array($booking->status, ['cancelled', 'completed'], true)) abort(422, 'Installment plans can only be created for active bookings.');
            $total = (float) $data['total_amount'];
            $downPayment = (float) ($data['down_payment'] ?? 0);
            $installmentAmount = (float) $data['installment_amount'];
            $count = (int) $data['number_of_installments'];

            // booking.remaining_amount already excludes verified payments. Only unpaid
            // obligations in active plans should reserve that remaining balance.
            $allocated = (float) Installment::where('booking_id', $booking->id)
                ->whereHas('plan', function ($q) {
                    $q->where('status', 'active');
                })
                ->sum('remaining_amount');
            $availableForPlans = max(0, round((float) $booking->remaining_amount - $allocated, 2));
            if ($total > $availableForPlans) {
                abort(422, 'Installment plan exceeds the unallocated booking balance. Available: '.number_format($availableForPlans, 2));
            }
            if ($downPayment > 0) {
                abort(422, 'Down payment must be recorded as a booking payment before creating the installment plan. Create the plan for the remaining financed amount only.');
            }
            if (round($installmentAmount * $count, 2) != round($total, 2)) {
                abort(422, 'All installments must equal the plan total amount.');
            }
            if ($data['frequency'] === 'custom' && $count > 1) {
                abort(422, 'Custom frequency requires an explicit installment schedule and is not supported by automatic plan generation.');
            }

            $startDate = \Carbon\Carbon::parse($data['start_date'])->startOfDay();
            if ($startDate->lt(\Carbon\Carbon::parse($booking->booking_date)->startOfDay())) {
                abort(422, 'Installment plan start date cannot be before the booking date.');
            }
            if (($data['status'] ?? 'active') !== 'active') {
                abort(422, 'New installment plans must start with active status.');
            }

            $plan = InstallmentPlan::create(array_merge($data, [
                'down_payment' => 0,
                'installment_amount' => $installmentAmount,
            ]));

            $date = $startDate;
            $months = ['monthly' => 1, 'quarterly' => 3, 'half_yearly' => 6, 'yearly' => 12][$data['frequency']];

            for ($i = 1; $i <= $count; $i++) {
                $due = $date->copy()->addMonthsNoOverflow($months * ($i - 1));
                Installment::create([
                    'installment_plan_id' => $plan->id,
                    'booking_id' => $booking->id,
                    'installment_number' => $i,
                    'due_date' => $due,
                    'amount' => $installmentAmount,
                    'paid_amount' => 0,
                    'remaining_amount' => $installmentAmount,
                    'status' => 'pending',
                ]);
            }

            return $plan;
        });

        return response()->json(['message' => 'Installment plan created.', 'plan' => $plan->load('installments')], 201);
    }

    public function show(InstallmentPlan $installmentPlan)
    {
        $this->scopeBranch(InstallmentPlan::query())->findOrFail($installmentPlan->id);
        return response()->json($installmentPlan->load(['booking.customer', 'booking.property', 'installments.payments']));
    }

    public function update(Request $request, InstallmentPlan $installmentPlan)
    {
        $data = $request->validate(['plan_name' => 'required|string|max:100', 'status' => 'required|in:active,completed,cancelled', 'notes' => 'nullable|string']);

        $plan = DB::transaction(function () use ($installmentPlan, $data) {
            $plan = $this->scopeBranch(InstallmentPlan::query())->lockForUpdate()->findOrFail($installmentPlan->id);
            $installmentIds = $plan->installments()->pluck('id');
            $hasPayments = Payment::whereIn('installment_id', $installmentIds)
                ->where('status', 'verified')
                ->exists();

            if ($data['status'] === 'cancelled' && $hasPayments) {
                abort(422, 'An installment plan with payments cannot be cancelled.');
            }
            if ($data['status'] === 'completed' && $plan->installments()->where('remaining_amount', '>', 0)->exists()) {
                abort(422, 'All installments must be fully paid before completing the plan.');
            }
            if ($plan->status === 'completed' && $data['status'] !== 'completed') {
                abort(422, 'A completed installment plan cannot be reopened.');
            }

            $plan->update($data);
            return $plan;
        });

        return response()->json(['message' => 'Installment plan updated.', 'plan' => $plan->fresh()->load('installments')]);
    }

    public function destroy(InstallmentPlan $installmentPlan)
    {
        $this->scopeBranch(InstallmentPlan::query())->findOrFail($installmentPlan->id);
        DB::transaction(function () use ($installmentPlan) {
            $plan = $this->scopeBranch(InstallmentPlan::query())->lockForUpdate()->findOrFail($installmentPlan->id);
            $installmentIds = $plan->installments()->pluck('id');
            if (Payment::whereIn('installment_id', $installmentIds)->where('status', 'verified')->exists()) {
                abort(422, 'Plans with verified payments cannot be deleted. Reverse the payments first.');
            }
            if (Payment::whereIn('installment_id', $installmentIds)->exists()) {
                abort(422, 'This plan has payment history and cannot be deleted. Cancel it instead.');
            }
            $plan->installments()->delete();
            $plan->delete();
        });
        return response()->json(['message' => 'Installment plan deleted.']);
    }
}
