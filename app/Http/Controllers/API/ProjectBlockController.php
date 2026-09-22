<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Http\Requests\StoreProjectBlockRequest;
use App\Http\Requests\UpdateProjectBlockRequest;
use App\Models\ProjectBlock;
use App\Models\Project;
use Illuminate\Http\Request;

class ProjectBlockController extends Controller
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
        $query = $this->applyBranchScope(ProjectBlock::with([
            'project:id,name,code'
        ]));

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->latest()->paginate(
                min(max((int) $request->get('per_page', 20), 1), 100)
            )
        );
    }

    public function store(StoreProjectBlockRequest $request)
    {
        $data = $request->validated();
        $project = $this->applyBranchScope(Project::query())
            ->where('is_active', true)
            ->findOrFail($data['project_id']);

        $data['area_unit'] = $data['area_unit'] ?? 'Marla';
        $data['total_units'] = $data['total_units'] ?? 0;

        $block = ProjectBlock::create($data);

        return response()->json([
            'message' => 'Block created successfully.',
            'data' => $block->load('project'),
        ], 201);
    }

    public function show($id)
    {
        $block = $this->applyBranchScope(ProjectBlock::with([
            'project',
            'properties'
        ]))->findOrFail($id);

        return response()->json([
            'data' => $block
        ]);
    }

    public function update(
        UpdateProjectBlockRequest $request,
        $id
    ) {
        $block = $this->applyBranchScope(ProjectBlock::query())
            ->findOrFail($id);
        $data = $request->validated();

        if (isset($data['project_id'])) {
            $this->applyBranchScope(Project::query())
                ->where('is_active', true)
                ->findOrFail($data['project_id']);

            if ((int) $data['project_id'] !== (int) $block->project_id &&
                $block->properties()->exists()) {
                abort(422, 'A block containing properties cannot be moved to another project.');
            }
        }

        $block->update($data);

        return response()->json([
            'message' => 'Block updated successfully.',
            'data' => $block->fresh()->load('project')
        ]);
    }

    public function destroy($id)
    {
        $block = $this->applyBranchScope(ProjectBlock::query())
            ->findOrFail($id);

        if ($block->properties()->exists()) {
            abort(422, 'Blocks containing properties cannot be deleted. Move or remove the properties first.');
        }

        $block->delete();

        return response()->json([
            'message' => 'Block deleted successfully.'
        ]);
    }
}