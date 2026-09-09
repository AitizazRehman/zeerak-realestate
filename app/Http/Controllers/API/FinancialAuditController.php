<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\FinancialAudit;
use Illuminate\Http\Request;

class FinancialAuditController extends Controller
{
    public function index(Request $request)
    {
        $query = FinancialAudit::with('user:id,name')
            ->orderByDesc('id');

        foreach (['entity_type', 'action', 'user_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->get($field));
            }
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->get('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->get('to'));
        }

        if ($request->filled('entity_id')) {
            $query->where('entity_id', (int) $request->get('entity_id'));
        }

        $perPage = min(max((int) $request->get('per_page', 25), 1), 100);

        return response()->json($query->paginate($perPage));
    }
}
