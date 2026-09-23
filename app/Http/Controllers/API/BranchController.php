<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BranchController extends Controller
{
    use ChecksBranchAccess;

    public function index(Request $request)
    {
        $query = Branch::query();

        if (!$this->canAccessAllBranches()) {
            $query->where('id', auth()->user()->branch_id);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->search.'%')
                    ->orWhere('code', 'like', '%'.$request->search.'%');
            });
        }

        $perPage = min(max((int) $request->get('per_page', 25), 1), 100);

        return response()->json(
            $query->orderBy('name')->paginate($perPage)
        );
    }

    public function store(Request $request)
    {
        if (!$this->canAccessAllBranches()) {
            abort(403, 'Only administrators can create branches.');
        }

        $data = $this->validateBranch($request);

        $branch = DB::transaction(function () use ($data) {
            if (!empty($data['is_head_office'])) {
                Branch::where('is_head_office', true)->update(['is_head_office' => false]);
            }

            return Branch::create($data);
        });

        return response()->json([
            'message' => 'Branch created successfully.',
            'data' => $branch
        ], 201);
    }

    public function update(Request $request, $id)
    {
        if (!$this->canAccessAllBranches()) {
            abort(403, 'Only administrators can update branches.');
        }

        $branch = Branch::findOrFail($id);
        $data = $this->validateBranch($request, $branch->id);

        if ($branch->is_head_office && array_key_exists('is_head_office', $data) && !$data['is_head_office']) {
            abort(422, 'To change Head Office, mark the new branch as Head Office instead. The existing Head Office will be updated automatically.');
        }

        if (array_key_exists('is_active', $data) && !$data['is_active'] && $branch->is_active) {
            $activeUsers = $branch->users()->where('is_active', true)->count();
            $activeProjects = $branch->projects()->where('is_active', true)->count();

            if ($activeUsers > 0 || $activeProjects > 0) {
                abort(422, 'This branch has active users or projects. Reassign or deactivate them before deactivating the branch.');
            }

            if ($branch->is_head_office) {
                abort(422, 'Head Office cannot be deactivated. Assign another active branch as Head Office first.');
            }
        }

        $branch = DB::transaction(function () use ($branch, $data) {
            if (!empty($data['is_head_office'])) {
                if (array_key_exists('is_active', $data) && !$data['is_active']) {
                    abort(422, 'Head Office must remain active.');
                }

                $data['is_active'] = true;

                Branch::where('id', '!=', $branch->id)
                    ->where('is_head_office', true)
                    ->update(['is_head_office' => false]);
            }

            $branch->update($data);

            return $branch->fresh();
        });

        return response()->json([
            'message' => 'Branch updated successfully.',
            'data' => $branch
        ]);
    }

    public function destroy($id)
    {
        if (!$this->canAccessAllBranches()) {
            abort(403, 'Only administrators can deactivate branches.');
        }

        $branch = Branch::findOrFail($id);

        if ($branch->is_head_office) {
            abort(422, 'Head Office cannot be deleted or deactivated. Assign another branch as Head Office first.');
        }

        $activeUsers = $branch->users()->where('is_active', true)->count();
        $activeProjects = $branch->projects()->where('is_active', true)->count();

        if ($activeUsers > 0 || $activeProjects > 0) {
            abort(422, 'This branch has active users or projects. Reassign or deactivate them before removing the branch.');
        }

        $usersCount = $branch->users()->count();
        $projectsCount = $branch->projects()->count();

        if ($usersCount > 0 || $projectsCount > 0) {
            $branch->update(['is_active' => false]);

            return response()->json([
                'message' => 'Branch has historical linked users/projects, so it was safely deactivated instead of deleted.'
            ]);
        }

        $branch->delete();

        return response()->json(['message' => 'Branch deleted successfully.']);
    }

    private function validateBranch(Request $request, $id = null)
    {
        return $request->validate([
            'name' => 'required|string|max:150',
            'code' => 'required|string|max:30|unique:branches,code'.($id ? ','.$id : ''),
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:150',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'manager_name' => 'nullable|string|max:150',
            'is_head_office' => 'boolean',
            'is_active' => 'boolean',
        ]);
    }

    public function show($id)
    {
        if (!$this->canAccessAllBranches()) {
            $this->ensureBranchAccess($id);
        }

        return response()->json([
            'data' => Branch::with([
                'users' => function ($query) {
                    $query->select('id', 'name', 'email', 'branch_id')
                        ->where('is_active', true)
                        ->orderBy('name');
                },
                'projects' => function ($query) {
                    $query->select('id', 'name', 'branch_id')
                        ->orderBy('name');
                }
            ])->findOrFail($id)
        ]);
    }
}
