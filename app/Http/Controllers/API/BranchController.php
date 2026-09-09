<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index(Request $request)
    {
        $query = Branch::query();

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

        return response()->json(
            $query->orderBy('name')->paginate(
                $request->get('per_page', 100)
            )
        );
    }

    public function show($id)
    {
        return response()->json([
            'data' => Branch::with([
                'users',
                'projects'
            ])->findOrFail($id)
        ]);
    }
}