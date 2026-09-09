<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\{Payment, Booking, Installment, PropertyStatusHistory, FinancialAudit};
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;

class PaymentController extends Controller
{
    use ChecksBranchAccess;

    private function applyBranchScope($query)
    {
        if (!$this->canAccessAllBranches()) {
            $query->whereHas('booking.property.project', function ($projectQuery) {
                $projectQuery->where('branch_id', auth()->user()->branch_id);
            });
        }

        return $query;
    }

    public function index(Request $r)
    {
        $q = $this->applyBranchScope(Payment::with([
            'customer', 'booking.property', 'installment', 'receivedBy:id,name', 'reversedBy:id,name'
        ]));

        foreach (['booking_id','customer_id','installment_id','payment_method','status'] as $f) {
            if ($r->filled($f)) $q->where($f, $r->$f);
        }
        if ($r->filled('from')) $q->whereDate('payment_date','>=',$r->from);
        if ($r->filled('to')) $q->whereDate('payment_date','<=',$r->to);

        $perPage = min(max((int)$r->get('per_page',15),1),100);
        return response()->json($q->latest('payment_date')->latest('id')->paginate($perPage));
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'booking_id'=>'required|exists:bookings,id',
            'installment_id'=>'nullable|exists:installments,id',
            'customer_id'=>'required|exists:customers,id',
            'amount'=>'required|numeric|min:0.01',
            'payment_date'=>'required|date',
            'payment_method'=>'required|in:cash,bank_transfer,cheque,online,other',
            'reference_number'=>'nullable|string|max:100',
            'bank_name'=>'nullable|string|max:100',
            'cheque_number'=>'nullable|string|max:100',
            'notes'=>'nullable|string'
        ]);

        $payment = DB::transaction(function() use ($d,$r) {
            $b = Booking::lockForUpdate()->findOrFail($d['booking_id']);
            $this->ensureBranchAccess($b->property->project->branch_id);

            if ($b->customer_id != $d['customer_id']) abort(422,'Customer does not belong to this booking.');
            if ($b->status === 'cancelled') abort(422,'Cancelled bookings cannot receive payments.');

            $amount = round((float)$d['amount'],2);
            if ($amount > round((float)$b->remaining_amount,2)) abort(422,'Payment exceeds booking remaining amount.');

            $installment = null;
            if (!empty($d['installment_id'])) {
                $installment = Installment::lockForUpdate()->findOrFail($d['installment_id']);
                if ($installment->booking_id != $b->id) abort(422,'Installment does not belong to this booking.');
                if ($installment->status === 'paid') abort(422,'This installment is already fully paid.');
                if ($amount > round((float)$installment->remaining_amount,2)) abort(422,'Payment exceeds installment remaining amount.');
            }

            $payment = Payment::create(array_merge($d,[
                'receipt_number'=>'REC-'.now()->format('Ym').'-'.strtoupper(Str::random(7)),
                'received_by'=>$r->user()->id,
                'status'=>'verified'
            ]));

            $this->recalculateBooking($b,$installment,$amount,true);
            FinancialAudit::create([
                'entity_type'=>'payment','entity_id'=>$payment->id,'action'=>'created',
                'user_id'=>$r->user()->id,'after_data'=>$payment->fresh()->toArray()
            ]);

            return $payment;
        });

        return response()->json([
            'message'=>'Payment recorded successfully.',
            'payment'=>$payment->load(['customer','booking.property','installment','receivedBy'])
        ],201);
    }

    public function show(Payment $payment)
    {
        $this->applyBranchScope(Payment::query())->findOrFail($payment->id);
        return response()->json($payment->load(['customer','booking.property.project','booking.property.block','installment','receivedBy','reversedBy']));
    }

    public function receipt(Payment $payment)
    {
        $this->applyBranchScope(Payment::query())->findOrFail($payment->id);
        $payment->load(['customer','booking.property.project','booking.property.block','installment','receivedBy','reversedBy']);
        return Pdf::loadView('payments.receipt',['payment'=>$payment])->setPaper('a4')->stream($payment->receipt_number.'.pdf');
    }

    public function reverse(Request $r,Payment $payment)
    {
        $this->applyBranchScope(Payment::query())->findOrFail($payment->id);
        $data=$r->validate(['reason'=>'required|string|max:1000']);

        $result=DB::transaction(function()use($payment,$data,$r){
            $p=Payment::lockForUpdate()->findOrFail($payment->id);
            $b=Booking::lockForUpdate()->findOrFail($p->booking_id);
            $this->ensureBranchAccess($b->property->project->branch_id);

            if($p->status==='reversed'||$p->reversed_at)abort(422,'This payment has already been reversed.');
            if($p->status!=='verified')abort(422,'Only verified payments can be reversed.');
            $before=$p->toArray();
            $amount=round((float)$p->amount,2);
            if((float)$b->paid_amount<$amount)abort(422,'Payment reversal would make booking balance invalid.');

            $b->paid_amount=max(0,round((float)$b->paid_amount-$amount,2));
            $b->remaining_amount=max(0,round((float)$b->final_price-(float)$b->paid_amount,2));
            $b->save();

            if($p->installment_id){
                $i=Installment::lockForUpdate()->findOrFail($p->installment_id);
                if($i->booking_id != $b->id) abort(422,'Installment does not belong to this booking.');
                $i->paid_amount=max(0,round((float)$i->paid_amount-$amount,2));
                $i->remaining_amount=max(0,round((float)$i->amount-(float)$i->paid_amount,2));
                $i->status=$i->paid_amount<=0?'pending':($i->remaining_amount<=0?'paid':'partial');
                $i->paid_date=$i->status==='paid'?$i->paid_date:null;
                $i->save();
            }

            $p->update(['status'=>'reversed','reversed_at'=>now(),'reversed_by'=>$r->user()->id,'reversal_reason'=>$data['reason']]);

            if($b->status==='completed'&&$b->remaining_amount>0){
                $b->update(['status'=>'confirmed']);
                $property=Property::lockForUpdate()->findOrFail($b->property_id);
                $old=$property->status;
                if($old==='sold'){
                    $property->update(['status'=>'booked']);
                    PropertyStatusHistory::create(['property_id'=>$property->id,'old_status'=>$old,'new_status'=>'booked','changed_by'=>auth()->id(),'notes'=>'Booking '.$b->booking_number.' reopened after payment reversal.']);
                }
            }

            $after=$p->fresh()->toArray();
            FinancialAudit::create(['entity_type'=>'payment','entity_id'=>$p->id,'action'=>'reversed','user_id'=>$r->user()->id,'before_data'=>$before,'after_data'=>$after,'reason'=>$data['reason']]);
            return $p;
        });

        return response()->json(['message'=>'Payment reversed successfully.','payment'=>$result->load(['customer','booking.property','installment','receivedBy','reversedBy'])]);
    }

    private function recalculateBooking(Booking $b,$installment,float $amount,bool $completeWhenPaid)
    {
        $b->paid_amount=round((float)$b->paid_amount+$amount,2);
        $b->remaining_amount=max(0,round((float)$b->final_price-(float)$b->paid_amount,2));
        $b->save();

        if($installment){
            $installment->paid_amount=round((float)$installment->paid_amount+$amount,2);
            $installment->remaining_amount=max(0,round((float)$installment->amount-(float)$installment->paid_amount,2));
            $installment->status=$installment->remaining_amount<=0?'paid':'partial';
            $installment->paid_date=$installment->remaining_amount<=0?now()->toDateString():null;
            $installment->save();
        }

        if($completeWhenPaid&&$b->remaining_amount<=0&&$b->status==='confirmed'){
            $property=Property::lockForUpdate()->findOrFail($b->property_id);
            $old=$property->status;
            $property->update(['status'=>'sold']);
            $b->update(['status'=>'completed']);
            if($old!=='sold')PropertyStatusHistory::create(['property_id'=>$property->id,'old_status'=>$old,'new_status'=>'sold','changed_by'=>auth()->id(),'notes'=>'Booking '.$b->booking_number.' fully paid.']);
        }
    }
}
