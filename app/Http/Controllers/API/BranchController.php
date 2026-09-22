<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Branch;
use Illuminate\Http\Request;

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
            $query->where(
                'is_active',
                $request->boolean('is_active')
            );
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where(
                    'name',
                    'like',
                    '%' . $request->search . '%'
                )->orWhere(
                    'code',
                    'like',
                    '%' . $request->search . '%'
                );
            });
        }

        $perPage = min(max((int) $request->get('per_page', 25), 1), 100);

        return response()->json(
            $query->orderBy('name')->paginate($perPage)
        );
    }

    public function store(Request $request)
    {
        if (!$this->canAccessAllBranches()) abort(403, 'Only administrators can create branches.');
        $data = $this->validateBranch($request);
        $branch = Branch::create($data);
        return response()->json(['message' => 'Branch created successfully.', 'data' => $branch], 201);
    }

    public function update(Request $request, $id)
    {
        if (!$this->canAccessAllBranches()) abort(403, 'Only administrators can update branches.');
        $branch = Branch::findOrFail($id);
        $data = $this->validateBranch($request, $branch->id);
        $branch->update($data);
        return response()->json(['message' => 'Branch updated successfully.', 'data' => $branch->fresh()]);
    }

    public function destroy($id)
    {
        if (!$this->canAccessAllBranches()) abort(403, 'Only administrators can deactivate branches.');
        $branch = Branch::withCount(['users', 'projects'])->findOrFail($id);
        if ($branch->is_head_office) abort(422, 'Head Office cannot be deleted. Edit it or mark another branch as Head Office first.');
        if ($branch->users_count > 0 || $branch->projects_count > 0) {
            $branch->update(['is_active' => false]);
            return response()->json(['message' => 'Branch has linked users/projects, so it was deactivated instead of deleted.']);
        }
        $branch->delete();
        return response()->json(['message' => 'Branch deleted successfully.']);
    }

    private function validateBranch(Request $request, $id = null)
    {
        $data = $request->validate([
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
        if (!empty($data['is_head_office'])) {
            Branch::where('is_head_office', true)->when($id, function ($q) use ($id) { $q->where('id', '!=', $id); })->update(['is_head_office' => false]);
        }
        return $data;
    }

    public function show($id)
    {
        if (!$this->canAccessAllBranches()) $this->ensureBranchAccess($id);

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