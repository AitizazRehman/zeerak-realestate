<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingController extends Controller
{
    public function index(Request $request){
        $q=Booking::with(['customer','property.project','property.block','salesAgent:id,name']);
        foreach(['customer_id','property_id','sales_agent_id','status'] as $f) if($request->filled($f)) $q->where($f,$request->$f);
        if($request->filled('search')){$s=$request->search;$q->where(function($x)use($s){$x->where('booking_number','like',"%{$s}%")->orWhereHas('customer',function($c)use($s){$c->where('name','like',"%{$s}%")->orWhere('phone','like',"%{$s}%");});});}
        return response()->json($q->latest('booking_date')->paginate($request->integer('per_page',15)));
    }
    public function store(Request $request){
        $data=$request->validate(['customer_id'=>'required|exists:customers,id','property_id'=>'required|exists:properties,id','sales_agent_id'=>'nullable|exists:users,id','discount'=>'nullable|numeric|min:0','booking_date'=>'nullable|date','notes'=>'nullable|string']);
        $booking=DB::transaction(function()use($data){
            $property=Property::lockForUpdate()->findOrFail($data['property_id']);
            if($property->status!=='available') abort(422,'Only available properties can be booked.');
            $price=(float)$property->price; $discount=(float)($data['discount']??$property->discount??0); $final=max(0,$price-$discount);
            $booking=Booking::create(array_merge($data,['booking_number'=>'BKG-'.now()->format('Ym').'-'.strtoupper(Str::random(6)),'status'=>'reserved','property_price'=>$price,'discount'=>$discount,'final_price'=>$final,'paid_amount'=>0,'remaining_amount'=>$final,'booking_date'=>$data['booking_date']??now()->toDateString()]));
            $property->update(['status'=>'reserved']); return $booking;
        });
        return response()->json(['message'=>'Booking created and property reserved.','booking'=>$booking->load(['customer','property.project','property.block','salesAgent'])],201);
    }
    public function show(Booking $booking){return response()->json($booking->load(['customer','property.project','property.block','salesAgent','installmentPlans.installments','payments','commissions']));}
    public function update(Request $request, Booking $booking){$data=$request->validate(['sales_agent_id'=>'nullable|exists:users,id','discount'=>'nullable|numeric|min:0','booking_date'=>'nullable|date','notes'=>'nullable|string']);$booking->update($data);return response()->json(['message'=>'Booking updated.','booking'=>$booking->fresh()->load(['customer','property','salesAgent'])]);}
    public function destroy(Booking $booking){if(in_array($booking->status,['completed','sold']))return response()->json(['message'=>'Completed bookings cannot be deleted.'],422);$booking->delete();return response()->json(['message'=>'Booking deleted.']);}
}
