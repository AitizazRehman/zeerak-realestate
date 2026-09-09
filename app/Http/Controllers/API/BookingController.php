<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Booking;
use App\Models\Property;
use App\Models\PropertyStatusHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingController extends Controller
{
    use ChecksBranchAccess;

    private function applyBranchScope($query)
    {
        if (!$this->canAccessAllBranches()) {
            $query->whereHas('property.project', function ($projectQuery) {
                $projectQuery->where('branch_id', auth()->user()->branch_id);
            });
        }

        return $query;
    }

    private function ensureSalesAgentAccess($agentId, $branchId)
    {
        if (!$agentId) {
            return;
        }

        $agent = User::role('Sales Agent')->findOrFail($agentId);

        if (!$this->canAccessAllBranches() && (int) $agent->branch_id !== (int) $branchId) {
            abort(422, 'The selected sales agent belongs to another branch.');
        }
    }

    public function index(Request $request)
    {
        $query = $this->applyBranchScope(Booking::with([
            'customer',
            'property.project',
            'property.block',
            'salesAgent:id,name'
        ]));

        foreach (['customer_id', 'property_id', 'sales_agent_id', 'status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->$field);
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('booking_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($c) use ($search) {
                        $c->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);

        return response()->json($query->latest('booking_date')->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'property_id' => 'required|exists:properties,id',
            'sales_agent_id' => 'nullable|exists:users,id',
            'discount' => 'nullable|numeric|min:0',
            'booking_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $booking = DB::transaction(function () use ($data, $request) {
            $property = Property::lockForUpdate()->findOrFail($data['property_id']);
            $this->ensureBranchAccess($property->project->branch_id);
            $this->ensureSalesAgentAccess($data['sales_agent_id'] ?? null, $property->project->branch_id);

            if ($property->status !== 'available') {
                abort(422, 'Only available properties can be booked.');
            }

            $price = (float) $property->price;
            $discount = (float) ($data['discount'] ?? $property->discount ?? 0);
            if ($discount > $price) {
                abort(422, 'Discount cannot exceed property price.');
            }

            $final = max(0, $price - $discount);
            $booking = Booking::create(array_merge($data, [
                'booking_number' => 'BKG-' . now()->format('Ym') . '-' . strtoupper(Str::random(6)),
                'status' => 'reserved',
                'property_price' => $price,
                'discount' => $discount,
                'final_price' => $final,
                'paid_amount' => 0,
                'remaining_amount' => $final,
                'booking_date' => $data['booking_date'] ?? now()->toDateString(),
            ]));

            $property->update(['status' => 'reserved']);
            PropertyStatusHistory::create([
                'property_id' => $property->id,
                'old_status' => 'available',
                'new_status' => 'reserved',
                'changed_by' => optional($request->user())->id,
                'notes' => 'Reserved by booking ' . $booking->booking_number,
            ]);

            return $booking;
        });

        return response()->json([
            'message' => 'Booking created and property reserved.',
            'booking' => $booking->load(['customer', 'property.project', 'property.block', 'salesAgent'])
        ], 201);
    }

    public function show(Booking $booking)
    {
        $this->applyBranchScope(Booking::query())->findOrFail($booking->id);

        return response()->json($booking->load([
            'customer',
            'property.project',
            'property.block',
            'salesAgent',
            'installmentPlans.installments',
            'payments',
            'commissions'
        ]));
    }

    public function update(Request $request, Booking $booking)
    {
        $this->applyBranchScope(Booking::query())->findOrFail($booking->id);

        if (in_array($booking->status, ['completed', 'cancelled'], true)) {
            return response()->json(['message' => 'This booking can no longer be edited.'], 422);
        }

        $data = $request->validate([
            'sales_agent_id' => 'nullable|exists:users,id',
            'discount' => 'nullable|numeric|min:0',
            'booking_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $branchId = $booking->property->project->branch_id;
        $this->ensureSalesAgentAccess($data['sales_agent_id'] ?? null, $branchId);

        if (array_key_exists('discount', $data)) {
            $discount = (float) $data['discount'];
            if ($discount > (float) $booking->property_price) {
                return response()->json(['message' => 'Discount cannot exceed booking property price.'], 422);
            }

            $data['final_price'] = max(0, (float) $booking->property_price - $discount);
            $data['remaining_amount'] = max(0, $data['final_price'] - (float) $booking->paid_amount);
        }

        $booking->update($data);

        return response()->json([
            'message' => 'Booking updated.',
            'booking' => $booking->fresh()->load(['customer', 'property', 'salesAgent'])
        ]);
    }

    public function destroy(Booking $booking)
    {
        $this->applyBranchScope(Booking::query())->findOrFail($booking->id);

        if (in_array($booking->status, ['completed', 'cancelled'], true)) {
            return response()->json(['message' => 'Completed or cancelled bookings cannot be deleted.'], 422);
        }

        $booking->delete();

        return response()->json(['message' => 'Booking deleted.']);
    }
}
