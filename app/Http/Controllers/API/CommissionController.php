<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommissionController extends Controller
{
    public function index(Request $r)
    {
        $q = Commission::with(['booking.customer','booking.property','agent:id,name']);
        if ($r->filled('agent_id')) $q->where('agent_id', (int)$r->agent_id);
        if ($r->filled('status')) $q->where('status', $r->status);
        $perPage = min(max((int)$r->get('per_page', 15), 1), 100);
        return response()->json($q->latest()->paginate($perPage));
    }

    public function store(Request $r)
    {
        $d = $r->validate(['booking_id'=>'required|exists:bookings,id','agent_id'=>'required|exists:users,id','percentage'=>'required|numeric|min:0|max:100','notes'=>'nullable|string']);
        $c = DB::transaction(function () use ($d) {
            $booking = Booking::lockForUpdate()->findOrFail($d['booking_id']);
            if (!$booking->sales_agent_id || (int)$booking->sales_agent_id !== (int)$d['agent_id']) abort(422, 'Commission agent must match the booking sales agent.');
            if (in_array($booking->status, ['cancelled'], true)) abort(422, 'Cancelled bookings cannot receive commissions.');
            if (Commission::where('booking_id',$booking->id)->where('agent_id',$d['agent_id'])->whereIn('status',['pending','approved','paid'])->exists()) abort(422, 'A commission already exists for this booking and agent.');
            $base = (float)$booking->final_price;
            $d['base_amount'] = $base;
            $d['commission_amount'] = round($base * (float)$d['percentage'] / 100, 2);
            $d['status'] = 'pending';
            return Commission::create($d);
        });
        return response()->json(['message'=>'Commission created.','commission'=>$c->load(['booking.customer','booking.property','agent'])],201);
    }

    public function update(Request $r, Commission $commission)
    {
        $d = $r->validate(['status'=>'required|in:pending,approved,paid,cancelled','notes'=>'nullable|string']);
        $allowed = ['pending'=>['pending','approved','cancelled'], 'approved'=>['approved','paid','cancelled'], 'paid'=>['paid'], 'cancelled'=>['cancelled']];
        if (!in_array($d['status'], $allowed[$commission->status] ?? [], true)) abort(422, 'Invalid commission status transition.');
        if ($d['status']==='approved') $d['approved_date'] = $commission->approved_date ?: now()->toDateString();
        if ($d['status']==='paid') $d['paid_date'] = $commission->paid_date ?: now()->toDateString();
        $commission->update($d);
        return response()->json(['message'=>'Commission updated.','commission'=>$commission->fresh()->load(['booking','agent'])]);
    }
}
