<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\{Payment, Booking, Installment, PropertyStatusHistory};
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function index(Request $r)
    {
        $q = Payment::with(['customer','booking.property','installment','receivedBy:id,name']);
        foreach (['booking_id','customer_id','installment_id','payment_method','status'] as $f) if ($r->filled($f)) $q->where($f, $r->$f);
        return response()->json($q->latest('payment_date')->paginate($r->integer('per_page',15)));
    }

    public function store(Request $r)
    {
        $d = $r->validate(['booking_id'=>'required|exists:bookings,id','installment_id'=>'nullable|exists:installments,id','customer_id'=>'required|exists:customers,id','amount'=>'required|numeric|min:0.01','payment_date'=>'required|date','payment_method'=>'required|in:cash,bank_transfer,cheque,online,other','reference_number'=>'nullable|string|max:100','bank_name'=>'nullable|string|max:100','cheque_number'=>'nullable|string|max:100','notes'=>'nullable|string']);
        $payment = DB::transaction(function () use ($d, $r) {
            $b = Booking::lockForUpdate()->findOrFail($d['booking_id']);
            if ($b->customer_id != $d['customer_id']) abort(422, 'Customer does not belong to this booking.');
            if ($b->status === 'cancelled') abort(422, 'Cancelled bookings cannot receive payments.');
            $amount = (float) $d['amount'];
            if ($amount > (float) $b->remaining_amount) abort(422, 'Payment exceeds booking remaining amount.');
            $installment = null;
            if (!empty($d['installment_id'])) {
                $installment = Installment::lockForUpdate()->findOrFail($d['installment_id']);
                if ($installment->booking_id != $b->id) abort(422, 'Installment does not belong to this booking.');
                if ($amount > (float) $installment->remaining_amount) abort(422, 'Payment exceeds installment remaining amount.');
            }
            $payment = Payment::create(array_merge($d, ['receipt_number'=>'REC-'.now()->format('Ym').'-'.strtoupper(Str::random(7)),'received_by'=>$r->user()->id,'status'=>'verified']));
            $b->paid_amount = (float)$b->paid_amount + $amount;
            $b->remaining_amount = max(0, (float)$b->final_price - (float)$b->paid_amount);
            $b->save();
            if ($installment) {
                $installment->paid_amount = (float)$installment->paid_amount + $amount;
                $installment->remaining_amount = max(0, (float)$installment->amount - (float)$installment->paid_amount);
                $installment->status = $installment->remaining_amount <= 0 ? 'paid' : 'partial';
                $installment->paid_date = $installment->remaining_amount <= 0 ? now()->toDateString() : null;
                $installment->save();
            }
            if ($b->remaining_amount <= 0 && $b->status === 'confirmed') {
                $property = Property::lockForUpdate()->findOrFail($b->property_id);
                $old = $property->status;
                $property->update(['status'=>'sold']);
                $b->update(['status'=>'completed']);
                if ($old !== 'sold') PropertyStatusHistory::create(['property_id'=>$property->id,'old_status'=>$old,'new_status'=>'sold','changed_by'=>auth()->id(),'notes'=>'Booking '.$b->booking_number.' fully paid.']);
            }
            return $payment;
        });
        return response()->json(['message'=>'Payment recorded successfully.','payment'=>$payment->load(['customer','booking.property','installment','receivedBy'])],201);
    }

    public function show(Payment $payment) { return response()->json($payment->load(['customer','booking.property.project','booking.property.block','installment','receivedBy'])); }
}
