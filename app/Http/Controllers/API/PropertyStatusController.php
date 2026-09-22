<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Property;
use App\Models\PropertyStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PropertyStatusController extends Controller
{
    use ChecksBranchAccess;

    private function checkProperty(Property $property)
    {
        $property->loadMissing('project');
        $this->ensureBranchAccess($property->project->branch_id);
    }
    public function update(
        Request $request,
        Property $property
    ) {
        $this->checkProperty($property);
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
            $property = Property::with('project')->lockForUpdate()->findOrFail($property->id);
            $this->ensureBranchAccess($property->project->branch_id);
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
        $this->checkProperty($property);
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