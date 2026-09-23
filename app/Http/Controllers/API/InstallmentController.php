<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Installment;
use Illuminate\Http\Request;

class InstallmentController extends Controller
{
    use ChecksBranchAccess;

    private function scopeBranch($query)
    {
        if (!$this->canAccessAllBranches()) {
            $query->whereHas('booking.property.project', function ($project) {
                $project->where('branch_id', auth()->user()->branch_id);
            });
        }

        return $query;
    }

    public function index(Request $request)
    {
        $query = $this->scopeBranch(
            Installment::with(['plan','booking.customer','booking.property'])
        );

        if ($request->filled('booking_id')) {
            $query->where('booking_id', $request->booking_id);
        }

        if ($request->filled('installment_plan_id')) {
            $query->where('installment_plan_id', $request->installment_plan_id);
        }

        if ($request->filled('status')) {
            if ($request->status === 'overdue') {
                $query->whereIn('status', ['pending','partial','overdue'])
                    ->whereDate('due_date', '<', now()->toDateString())
                    ->where('remaining_amount', '>', 0);
            } else {
                $query->where('status', $request->status);
            }
        }

        if ($request->filled('from')) {
            $query->whereDate('due_date', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('due_date', '<=', $request->to);
        }

        return response()->json(
            $query->orderBy('due_date')
                ->paginate(min(max((int) $request->get('per_page', 20), 1), 100))
        );
    }

    public function show(Installment $installment)
    {
        $this->scopeBranch(Installment::query())->findOrFail($installment->id);

        return response()->json(
            $installment->load([
                'plan',
                'booking.customer',
                'booking.property.project',
                'payments'
            ])
        );
    }
}
