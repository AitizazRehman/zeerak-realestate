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
        if (!$branchId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($audit) use ($branchId) {
            $audit->where(function ($q) use ($branchId) {
                $q->where('entity_type', 'payment')
                  ->whereExists(function ($sub) use ($branchId) {
                      $sub->selectRaw('1')
                          ->from('payments')
                          ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
                          ->join('properties', 'properties.id', '=', 'bookings.property_id')
                          ->join('projects', 'projects.id', '=', 'properties.project_id')
                          ->whereColumn('payments.id', 'financial_audits.entity_id')
                          ->where('projects.branch_id', $branchId);
                  });
            })->orWhere(function ($q) use ($branchId) {
                $q->where('entity_type', 'booking')
                  ->whereExists(function ($sub) use ($branchId) {
                      $sub->selectRaw('1')
                          ->from('bookings')
                          ->join('properties', 'properties.id', '=', 'bookings.property_id')
                          ->join('projects', 'projects.id', '=', 'properties.project_id')
                          ->whereColumn('bookings.id', 'financial_audits.entity_id')
                          ->where('projects.branch_id', $branchId);
                  });
            })->orWhere(function ($q) use ($branchId) {
                $q->where('entity_type', 'expense')
                  ->whereExists(function ($sub) use ($branchId) {
                      $sub->selectRaw('1')
                          ->from('expenses')
                          ->leftJoin('properties', 'properties.id', '=', 'expenses.property_id')
                          ->leftJoin('projects as direct_projects', 'direct_projects.id', '=', 'expenses.project_id')
                          ->leftJoin('projects as property_projects', 'property_projects.id', '=', 'properties.project_id')
                          ->whereColumn('expenses.id', 'financial_audits.entity_id')
                          ->where(function ($branch) use ($branchId) {
                              $branch->where('direct_projects.branch_id', $branchId)
                                     ->orWhere('property_projects.branch_id', $branchId);
                          });
                  });
            })->orWhere(function ($q) use ($branchId) {
                $q->where('entity_type', 'commission')
                  ->whereExists(function ($sub) use ($branchId) {
                      $sub->selectRaw('1')
                          ->from('commissions')
                          ->join('bookings', 'bookings.id', '=', 'commissions.booking_id')
                          ->join('properties', 'properties.id', '=', 'bookings.property_id')
                          ->join('projects', 'projects.id', '=', 'properties.project_id')
                          ->whereColumn('commissions.id', 'financial_audits.entity_id')
                          ->where('projects.branch_id', $branchId);
                  });
            });
        });
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
