<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Property;
use App\Models\PropertyImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PropertyImageController extends Controller
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
        $request->validate([
            'images' => [
                'required',
                'array',
            ],

            'images.*' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ]);

        foreach ($request->file('images') as $index => $image) {

            $path = $image->store(
                'properties/' . $property->id,
                'public'
            );

            $property->images()->create([
                'file_path' => $path,
                'title' => $image->getClientOriginalName(),
                'is_primary' => $property->images()->count() === 0,
                'sort_order' => $index,
            ]);
        }

        return response()->json([
            'message' => 'Property images uploaded successfully.',
            'images' => $property->images()->get(),
        ]);
    }

    public function destroy(PropertyImage $image)
    {
        $image->loadMissing('property.project');
        $this->checkProperty($image->property);
        if ($image->file_path) {
            Storage::disk('public')
                ->delete($image->file_path);
        }

        $wasPrimary = (bool) $image->is_primary;
        $property = $image->property;
        $image->delete();

        if ($wasPrimary) {
            $replacement = $property->images()->orderBy('sort_order')->orderBy('id')->first();
            if ($replacement) $replacement->update(['is_primary' => true]);
        }

        return response()->json([
            'message' => 'Property image deleted successfully.',
        ]);
    }
    public function primary(PropertyImage $image)
    {
        $image->loadMissing('property.project');
        $property = $image->property;
        $this->checkProperty($property);

        $property->images()->update([
            'is_primary' => false
        ]);

        $image->update([
            'is_primary' => true
        ]);

        return response()->json([
            'message' =>
            'Primary image updated successfully.',
            'image' => $image->fresh(),
        ]);
    }
}
