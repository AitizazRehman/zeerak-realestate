<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $query = Project::with([
            'branch:id,name,code'
        ]);

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%");
            });
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('is_active')) {
            $query->where(
                'is_active',
                $request->boolean('is_active')
            );
        }

        return response()->json(
            $query->latest()->paginate(
                $request->get('per_page', 15)
            )
        );
    }

    public function store(StoreProjectRequest $request)
    {
        $data = $request->validated();

        $data['area_unit'] = $data['area_unit'] ?? 'Marla';
        $data['status'] = $data['status'] ?? 'planning';
        $data['construction_progress'] =
            $data['construction_progress'] ?? 0;
        $data['budget'] = $data['budget'] ?? 0;
        $data['actual_cost'] = $data['actual_cost'] ?? 0;

        $project = Project::create($data);

        return response()->json([
            'message' => 'Project created successfully.',
            'data' => $project->load('branch'),
        ], 201);
    }

    public function show($id)
    {
        $project = Project::with([
            'branch',
            'blocks',
            'properties'
        ])->findOrFail($id);

        return response()->json([
            'data' => $project
        ]);
    }

    public function update(
        UpdateProjectRequest $request,
        $id
    ) {
        $project = Project::findOrFail($id);

        $project->update($request->validated());

        return response()->json([
            'message' => 'Project updated successfully.',
            'data' => $project->fresh()->load('branch')
        ]);
    }

    public function destroy($id)
    {
        $project = Project::findOrFail($id);

        $project->delete();

        return response()->json([
            'message' => 'Project deleted successfully.'
        ]);
    }
}