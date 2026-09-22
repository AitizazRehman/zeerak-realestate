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