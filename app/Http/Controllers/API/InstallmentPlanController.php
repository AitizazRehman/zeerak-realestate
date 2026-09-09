<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Installment;
use App\Models\InstallmentPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InstallmentPlanController extends Controller
{
    public function index(Request $request)
    {
        $query = InstallmentPlan::with(['booking.customer', 'booking.property']);
        if ($request->filled('booking_id')) $query->where('booking_id', $request->booking_id);
        if ($request->filled('status')) $query->where('status', $request->status);
        return response()->json($query->latest()->paginate(min((int) $request->get('per_page', 15), 100)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'plan_name' => 'required|string|max:100',
            'frequency' => 'required|in:monthly,quarterly,half_yearly,yearly,custom',
            'total_amount' => 'required|numeric|min:0',
            'down_payment' => 'nullable|numeric|min:0',
            'installment_amount' => 'required|numeric|min:0',
            'number_of_installments' => 'required|integer|min:1',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'nullable|in:active,completed,cancelled',
            'notes' => 'nullable|string',
        ]);

        $plan = DB::transaction(function () use ($data) {
            $booking = Booking::lockForUpdate()->findOrFail($data['booking_id']);
            $total = (float) $data['total_amount'];
            $downPayment = (float) ($data['down_payment'] ?? 0);
            $installmentAmount = (float) $data['installment_amount'];
            $count = (int) $data['number_of_installments'];

            if ($total > (float) $booking->remaining_amount) {
                abort(422, 'Installment plan cannot exceed the booking remaining amount.');
            }
            if ($downPayment > $total) abort(422, 'Down payment cannot exceed plan total.');
            if (round($downPayment + ($installmentAmount * $count), 2) != round($total, 2)) {
                abort(422, 'Down payment plus all installments must equal the plan total amount.');
            }

            $plan = InstallmentPlan::create(array_merge($data, [
                'down_payment' => $downPayment,
                'installment_amount' => $installmentAmount,
            ]));

            $date = \Carbon\Carbon::parse($data['start_date']);
            $months = ['monthly' => 1, 'quarterly' => 3, 'half_yearly' => 6, 'yearly' => 12, 'custom' => 1][$data['frequency']];

            for ($i = 1; $i <= $count; $i++) {
                $due = $date->copy()->addMonths($months * ($i - 1));
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
        return response()->json($installmentPlan->load(['booking.customer', 'booking.property', 'installments.payments']));
    }

    public function update(Request $request, InstallmentPlan $installmentPlan)
    {
        $data = $request->validate(['plan_name' => 'required|string|max:100', 'status' => 'required|in:active,completed,cancelled', 'notes' => 'nullable|string']);
        $installmentPlan->update($data);
        return response()->json(['message' => 'Installment plan updated.', 'plan' => $installmentPlan->fresh()->load('installments')]);
    }

    public function destroy(InstallmentPlan $installmentPlan)
    {
        if ($installmentPlan->installments()->whereIn('status', ['paid', 'partial'])->exists()) abort(422, 'Plans with payments cannot be deleted.');
        $installmentPlan->delete();
        return response()->json(['message' => 'Installment plan deleted.']);
    }
}
