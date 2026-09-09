<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PropertyStatusController extends Controller
{
    public function update(
        Request $request,
        Property $property
    ) {
        $validated = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    'available',
                    'reserved',
                    'booked',
                    'sold',
                    'under_construction',
                    'rented',
                    'unavailable',
                    'cancelled',
                ]),
            ],

            'notes' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        return DB::transaction(function () use (
            $property,
            $validated
        ) {

            $oldStatus = $property->status;

            if ($oldStatus === $validated['status']) {
                return response()->json([
                    'message' => 'Property already has this status.',
                    'property' => $property,
                ]);
            }

            $property->update([
                'status' => $validated['status'],
            ]);

            PropertyStatusHistory::create([
                'property_id' => $property->id,
                'old_status' => $oldStatus,
                'new_status' => $validated['status'],
                'changed_by' => auth()->id(),
                'notes' => $validated['notes'] ?? null,
            ]);

            return response()->json([
                'message' => 'Property status updated successfully.',
                'property' => $property->fresh(),
            ]);
        });
    }

    public function history(Property $property)
    {
        $history = $property
            ->statusHistories()
            ->with('changedBy:id,name,email')
            ->latest()
            ->get();

        return response()->json([
            'history' => $history,
        ]);
    }
}