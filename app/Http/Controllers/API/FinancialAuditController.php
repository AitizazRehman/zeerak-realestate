<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\FinancialAudit;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;

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

    private function filteredQuery(Request $request)
    {
        $query = $this->applyBranchScope(FinancialAudit::with('user:id,name'));

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

        if ($request->filled('search')) {
            $search = trim((string) $request->get('search'));
            $query->where(function ($q) use ($search) {
                $q->where('reason', 'like', '%'.$search.'%')
                    ->orWhere('action', 'like', '%'.$search.'%')
                    ->orWhere('entity_type', 'like', '%'.$search.'%')
                    ->orWhereHas('user', function ($user) use ($search) {
                        $user->where('name', 'like', '%'.$search.'%');
                    });
            });
        }

        return $query;
    }

    public function index(Request $request)
    {
        $query = $this->filteredQuery($request)->orderByDesc('id');
        $perPage = min(max((int) $request->get('per_page', 25), 1), 100);

        return response()->json($query->paginate($perPage));
    }

    public function export(Request $request, $format)
    {
        if (!in_array($format, ['xls','pdf'], true)) {
            abort(404, 'Unknown export format.');
        }

        $from = $request->filled('from') ? $request->get('from') : 'All';
        $to = $request->filled('to') ? $request->get('to') : now()->toDateString();

        $audits = $this->filteredQuery($request)
            ->orderByDesc('id')
            ->get();

        $rows = $audits->map(function ($audit) {
            return [
                'Audit ID' => $audit->id,
                'Entity' => $audit->entity_type.' #'.$audit->entity_id,
                'Action' => $audit->action,
                'User' => $audit->user ? $audit->user->name : 'System',
                'Date' => optional($audit->created_at)->format('Y-m-d H:i:s'),
                'Reason' => $audit->reason,
            ];
        });

        $title = 'Financial Audit Trail';
        $file = 'financial-audit-'.now()->format('Ymd-His');

        if ($format === 'pdf') {
            return Pdf::loadView('reports.financial', compact('title','rows','from','to'))
                ->setPaper('a4', 'landscape')
                ->download($file.'.pdf');
        }

        $html = view('reports.financial', compact('title','rows','from','to'))->render();

        return response("\xEF\xBB\xBF".$html, 200, [
            'Content-Type'=>'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition'=>'attachment; filename="'.$file.'.xls"',
            'Cache-Control'=>'max-age=0',
        ]);
    }
}
