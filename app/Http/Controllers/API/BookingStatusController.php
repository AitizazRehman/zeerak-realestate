<?php
namespace App\Http\Controllers\API;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Property;
use Illuminate\Support\Facades\DB;
class BookingStatusController extends Controller{
 public function confirm(Booking $booking){return $this->change($booking,'booked','confirmed');}
 public function cancel(Booking $booking){if(in_array($booking->status,['completed','sold']))abort(422,'Completed bookings cannot be cancelled.');return $this->change($booking,'available','cancelled');}
 public function complete(Booking $booking){if((float)$booking->remaining_amount>0)abort(422,'Booking cannot be completed until fully paid.');return $this->change($booking,'sold','completed');}
 protected function change(Booking $booking,$propertyStatus,$bookingStatus){$result=DB::transaction(function()use($booking,$propertyStatus,$bookingStatus){$b=Booking::lockForUpdate()->findOrFail($booking->id);$p=Property::lockForUpdate()->findOrFail($b->property_id);if($bookingStatus==='booked'&&$b->status!=='reserved')abort(422,'Only reserved bookings can be confirmed.');$p->update(['status'=>$propertyStatus]);$b->update(['status'=>$bookingStatus]);return $b;});return response()->json(['message'=>'Booking status updated successfully.','booking'=>$result->load(['customer','property'])]);}
}
