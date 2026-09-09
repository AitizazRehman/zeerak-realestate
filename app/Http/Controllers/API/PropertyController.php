<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePropertyRequest;
use App\Http\Requests\UpdatePropertyRequest;
use App\Models\Property;
use App\Models\PropertyStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PropertyController extends Controller
{
    public function index(Request $request)
    {
        $query = Property::with([
            'project:id,name,code',
            'block:id,project_id,name,code',
            'assignedAgent:id,name,email',
            'images',
        ]);

        if ($request->filled('project_id')) {
            $query->where(
                'project_id',
                $request->project_id
            );
        }

        if ($request->filled('block_id')) {
            $query->where(
                'block_id',
                $request->block_id
            );
        }

        if ($request->filled('status')) {
            $query->where(
                'status',
                $request->status
            );
        }

        if ($request->filled('property_type')) {
            $query->where(
                'property_type',
                $request->property_type
            );
        }

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where(
                    'property_number',
                    'like',
                    "%{$search}%"
                )
                    ->orWhere(
                        'address',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'description',
                        'like',
                        "%{$search}%"
                    );
            });
        }

        if ($request->has('is_published')) {
            $query->where(
                'is_published',
                $request->boolean('is_published')
            );
        }

        $perPage = min(
            (int) $request->get('per_page', 20),
            100
        );

        return response()->json(
            $query
                ->latest()
                ->paginate($perPage)
        );
    }

    public function inventory(Request $request)
    {
        $baseQuery = Property::query();

        $this->applyInventoryFilters($baseQuery, $request);

        $summary = [
            'total' => (clone $baseQuery)->count(),

            'available' => (clone $baseQuery)
                ->where('status', 'available')
                ->count(),

            'reserved' => (clone $baseQuery)
                ->where('status', 'reserved')
                ->count(),

            'booked' => (clone $baseQuery)
                ->where('status', 'booked')
                ->count(),

            'sold' => (clone $baseQuery)
                ->where('status', 'sold')
                ->count(),

            'rented' => (clone $baseQuery)
                ->where('status', 'rented')
                ->count(),

            'under_construction' => (clone $baseQuery)
                ->where('status', 'under_construction')
                ->count(),

            'unavailable' => (clone $baseQuery)
                ->where('status', 'unavailable')
                ->count(),
        ];

        $properties = $baseQuery
            ->with([
                'project:id,name,code',
                'block:id,name,code',
                'assignedAgent:id,name,email',
                'images' => function ($query) {
                    $query->orderBy('is_primary', 'desc')
                        ->orderBy('sort_order');
                },
            ])
            ->latest()
            ->paginate(
                $request->get('per_page', 24)
            );

        return response()->json([
            'summary' => $summary,
            'properties' => $properties,
        ]);
    }

    public function store(StorePropertyRequest $request)
    {
        return DB::transaction(function () use ($request) {

            $data = $request->validated();

            $property = Property::create($data);

            PropertyStatusHistory::create([
                'property_id' => $property->id,
                'old_status' => null,
                'new_status' => $property->status,
                'changed_by' => auth()->id(),
                'notes' => 'Property created.',
            ]);

            $property->load([
                'project',
                'block',
                'assignedAgent',
                'images',
                'documents',
                'features',
                'statusHistories',
            ]);

            return response()->json([
                'message' => 'Property created successfully.',
                'property' => $property,
            ], 201);
        });
    }

    public function show(Property $property)
    {
        $property->load([
            'project',
            'block',
            'assignedAgent',
            'images',
            'documents',
            'features',
            'statusHistories.changedBy',
        ]);

        return response()->json([
            'property' => $property,
        ]);
    }

    public function update(
        UpdatePropertyRequest $request,
        Property $property
    ) {
        return DB::transaction(function () use (
            $request,
            $property
        ) {

            $oldStatus = $property->status;

            $property->update(
                $request->validated()
            );

            if ($oldStatus !== $property->status) {

                PropertyStatusHistory::create([
                    'property_id' => $property->id,
                    'old_status' => $oldStatus,
                    'new_status' => $property->status,
                    'changed_by' => auth()->id(),
                    'notes' => 'Property status changed.',
                ]);
            }

            $property->load([
                'project',
                'block',
                'assignedAgent',
                'images',
                'documents',
                'features',
                'statusHistories',
            ]);

            return response()->json([
                'message' => 'Property updated successfully.',
                'property' => $property,
            ]);
        });
    }

    public function destroy(Property $property)
    {
        $property->delete();

        return response()->json([
            'message' => 'Property deleted successfully.',
        ]);
    }
    private function applyInventoryFilters($query, Request $request)
    {
        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        if ($request->filled('block_id')) {
            $query->where('block_id', $request->block_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('property_type')) {
            $query->where('property_type', $request->property_type);
        }

        if ($request->filled('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->filled('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('property_number', 'like', "%{$search}%")
                    ->orWhere('property_type', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        return $query;
    }
}
