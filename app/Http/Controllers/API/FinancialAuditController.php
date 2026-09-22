<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\FinancialAudit;
use Illuminate\Http\Request;

class FinancialAuditController extends Controller
{
    use ChecksBranchAccess;

    private function applyBranchScope($query)
    {
        if ($this->canAccessAllBranches()) return $query;

        $branchId = auth()->user()->branch_id;
        if (!$branchId) return $query->whereRaw('1 = 0');

        return $query->where('branch_id', $branchId);
    }

    public function index(Request $request)
    {
        $query = $this->applyBranchScope(FinancialAudit::with('user:id,name'))
            ->orderByDesc('id');

        foreach (['entity_type', 'action', 'user_id'] as $field) {
            if ($request->filled($field)) $query->where($field, $request->get($field));
        }
        if ($request->filled('from')) $query->whereDate('created_at', '>=', $request->get('from'));
        if ($request->filled('to')) $query->whereDate('created_at', '<=', $request->get('to'));
        if ($request->filled('entity_id')) $query->where('entity_id', (int) $request->get('entity_id'));

        $perPage = min(max((int) $request->get('per_page', 25), 1), 100);
        return response()->json($query->paginate($perPage));
    }
}
