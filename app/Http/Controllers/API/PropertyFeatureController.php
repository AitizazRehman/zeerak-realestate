<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Property;
use App\Models\PropertyFeature;
use Illuminate\Http\Request;

class PropertyFeatureController extends Controller
{
    use ChecksBranchAccess;

    private function checkProperty(Property $property)
    {
        $property->loadMissing('project');
        $this->ensureBranchAccess($property->project->branch_id);
    }
    public function store(
        Request $request,
        Property $property
    ) {
        $this->checkProperty($property);
        $validated = $request->validate([
            'features' => [
                'required',
                'array',
            ],

            'features.*.feature_name' => [
                'required',
                'string',
                'max:100',
            ],

            'features.*.feature_value' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        foreach ($validated['features'] as $feature) {

            PropertyFeature::updateOrCreate(
                [
                    'property_id' => $property->id,
                    'feature_name' => $feature['feature_name'],
                ],
                [
                    'feature_value' =>
                        $feature['feature_value'] ?? null,
                ]
            );
        }

        return response()->json([
            'message' => 'Property features saved successfully.',
            'features' => $property->features()->get(),
        ]);
    }

    public function destroy(PropertyFeature $feature)
    {
        $feature->loadMissing('property.project');
        $this->checkProperty($feature->property);
        $feature->delete();

        return response()->json([
            'message' => 'Property feature deleted successfully.',
        ]);
    }
}