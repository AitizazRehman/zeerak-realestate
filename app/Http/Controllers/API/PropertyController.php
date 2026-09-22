<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Http\Requests\StorePropertyRequest;
use App\Http\Requests\UpdatePropertyRequest;
use App\Models\Property;
use App\Models\Project;
use App\Models\ProjectBlock;
use App\Models\PropertyStatusHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PropertyController extends Controller
{
    use ChecksBranchAccess;

    private function applyBranchScope($query)
    {
        if (!$this->canAccessAllBranches()) {
            $query->whereHas('project', function ($projectQuery) {
                $projectQuery->where('branch_id', auth()->user()->branch_id);
            });
        }

        return $query;
    }

    public function index(Request $request)
    {
        $query = $this->applyBranchScope(Property::with([
            'project:id,name,code',
            'block:id,project_id,name,code',
            'assignedAgent:id,name,email',
            'images',
        ]));

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

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('property_number', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_published')) {
            $query->where('is_published', $request->boolean('is_published'));
        }

        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

        return response()->json(
            $query->latest()->paginate($perPage)
        );
    }

    public function inventory(Request $request)
    {
        $baseQuery = $this->applyBranchScope(Property::query());

        $this->applyInventoryFilters($baseQuery, $request);

        $summary = [
            'total' => (clone $baseQuery)->count(),
            'available' => (clone $baseQuery)->where('status', 'available')->count(),
            'reserved' => (clone $baseQuery)->where('status', 'reserved')->count(),
            'booked' => (clone $baseQuery)->where('status', 'booked')->count(),
            'sold' => (clone $baseQuery)->where('status', 'sold')->count(),
            'rented' => (clone $baseQuery)->where('status', 'rented')->count(),
            'under_construction' => (clone $baseQuery)->where('status', 'under_construction')->count(),
            'unavailable' => (clone $baseQuery)->where('status', 'unavailable')->count(),
        ];

        $properties = $baseQuery
            ->with([
                'project:id,name,code',
                'block:id,name,code',
                'assignedAgent:id,name,email',
                'images' => function ($query) {
                    $query->orderBy('is_primary', 'desc')->orderBy('sort_order');
                },
            ])
            ->latest()
            ->paginate(min(max((int) $request->get('per_page', 24), 1), 100));

        return response()->json([
            'summary' => $summary,
            'properties' => $properties,
        ]);
    }

    private function validateRelations(array $data, $currentProjectId = null, $currentBlockId = null)
    {
        $projectId = array_key_exists('project_id', $data) ? $data['project_id'] : $currentProjectId;
        $blockId = array_key_exists('block_id', $data) ? $data['block_id'] : $currentBlockId;
        if ($projectId) {
            $this->applyBranchScope(Project::query())
                ->where('is_active', true)
                ->findOrFail($projectId);
        }

        if (!empty($data['assigned_agent_id'])) {
            $agent = User::role('Sales Agent')->where('is_active', true)->findOrFail($data['assigned_agent_id']);
            if ($projectId) {
                $project = Project::findOrFail($projectId);
                if ((int) $agent->branch_id !== (int) $project->branch_id) abort(422, 'Assigned sales agent must belong to the property branch.');
            }
        }

        if ($blockId) {
            $block = ProjectBlock::findOrFail($blockId);
            $this->applyBranchScope(Project::query())->findOrFail($block->project_id);
            if ($projectId && (int) $block->project_id !== (int) $projectId) {
                abort(422, 'Selected block does not belong to the selected project.');
            }
        }

        return $data;
    }

    public function store(StorePropertyRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $data = $request->validated();

            $data = $this->validateRelations($data);
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
        $this->applyBranchScope(Property::query())->findOrFail($property->id);

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
        return DB::transaction(function () use ($request, $property) {
            $property = $this->applyBranchScope(Property::query())->lockForUpdate()->findOrFail($property->id);
            $data = $this->validateRelations($request->validated(), $property->project_id, $property->block_id);

            $newProjectId = array_key_exists('project_id', $data) ? $data['project_id'] : $property->project_id;
            $newBlockId = array_key_exists('block_id', $data) ? $data['block_id'] : $property->block_id;
            $ownershipChanged = (int) $newProjectId !== (int) $property->project_id ||
                (int) $newBlockId !== (int) $property->block_id;

            if ($ownershipChanged && (
                $property->bookings()->exists() ||
                $property->statusHistories()->count() > 1
            )) {
                abort(422, 'A property with booking or status history cannot be moved to another project or block.');
            }

            $oldStatus = $property->status;
            $property->update($data);

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
        $property = $this->applyBranchScope(Property::query())->findOrFail($property->id);

        if ($property->bookings()->exists()) {
            abort(422, 'Property with booking history cannot be deleted. Mark it unavailable instead.');
        }
        if ($property->statusHistories()->count() > 1) {
            abort(422, 'Property with status history cannot be deleted. Mark it unavailable instead.');
        }

        $imagePaths = $property->images()->whereNotNull('file_path')->pluck('file_path')->all();
        $documentPaths = $property->documents()->whereNotNull('file_path')->pluck('file_path')->all();

        DB::transaction(function () use ($property) {
            $property->images()->delete();
            $property->documents()->delete();
            $property->features()->delete();
            $property->statusHistories()->delete();
            $property->delete();
        });

        foreach ($imagePaths as $path) {
            Storage::disk('public')->delete($path);
        }
        foreach ($documentPaths as $path) {
            Storage::disk('local')->delete($path);
        }

        Storage::disk('public')->deleteDirectory('properties/' . $property->id);
        Storage::disk('local')->deleteDirectory('properties/' . $property->id);

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
