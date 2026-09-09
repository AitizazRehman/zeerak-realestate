<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Property;
use App\Models\PropertyStatusHistory;
use Illuminate\Support\Facades\DB;

class BookingStatusController extends Controller
{
    public function confirm(Booking $booking) { return $this->change($booking, 'booked', 'confirmed'); }

    public function cancel(Booking $booking)
    {
        if (in_array($booking->status, ['completed', 'cancelled'], true)) abort(422, 'This booking cannot be cancelled.');
        return $this->change($booking, 'available', 'cancelled');
    }

    public function complete(Booking $booking)
    {
        if ((float) $booking->remaining_amount > 0) abort(422, 'Booking cannot be completed until fully paid.');
        if ($booking->status !== 'confirmed') abort(422, 'Only confirmed bookings can be completed.');
        return $this->change($booking, 'sold', 'completed');
    }

    protected function change(Booking $booking, string $propertyStatus, string $bookingStatus)
    {
        $result = DB::transaction(function () use ($booking, $propertyStatus, $bookingStatus) {
            $b = Booking::lockForUpdate()->findOrFail($booking->id);
            $p = Property::lockForUpdate()->findOrFail($b->property_id);
            $oldPropertyStatus = $p->status;

            if ($bookingStatus === 'confirmed' && $b->status !== 'reserved') abort(422, 'Only reserved bookings can be confirmed.');
            if ($bookingStatus === 'cancelled' && !in_array($b->status, ['reserved', 'confirmed'], true)) abort(422, 'This booking cannot be cancelled.');
            if ($bookingStatus === 'completed' && $b->status !== 'confirmed') abort(422, 'Only confirmed bookings can be completed.');

            $p->update(['status' => $propertyStatus]);
            $b->update(['status' => $bookingStatus]);

            if ($oldPropertyStatus !== $propertyStatus) {
                PropertyStatusHistory::create([
                    'property_id' => $p->id,
                    'old_status' => $oldPropertyStatus,
                    'new_status' => $propertyStatus,
                    'changed_by' => auth()->id(),
                    'notes' => 'Booking '.$b->booking_number.' status changed to '.$bookingStatus,
                ]);
            }

            return $b;
        });

        return response()->json([
            'message' => 'Booking status updated successfully.',
            'booking' => $result->load(['customer', 'property']),
        ]);
    }
}
