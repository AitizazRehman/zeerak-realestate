<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Project;
use App\Models\Property;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExpenseController extends Controller
{
    use ChecksBranchAccess;

    private function applyBranchScope($query)
    {
        if (!$this->canAccessAllBranches()) {
            $query->whereHas('project', function ($q) {
                $q->where('branch_id', auth()->user()->branch_id);
            });
        }
        return $query;
    }

    private function validateBranchRefs(array $data)
    {
        if (!empty($data['project_id'])) {
            $project = Project::findOrFail($data['project_id']);
            $this->ensureBranchAccess($project->branch_id);
        }

        if (!empty($data['property_id'])) {
            $property = Property::with('project')->findOrFail($data['property_id']);
            $this->ensureBranchAccess($property->project->branch_id);

            if (!empty($data['project_id']) && (int) $property->project_id !== (int) $data['project_id']) {
                abort(422, 'Property does not belong to the selected project.');
            }
        }

        if (empty($data['project_id']) && !empty($data['property_id'])) {
            $data['project_id'] = Property::findOrFail($data['property_id'])->project_id;
        }

        return $data;
    }

    public function index(Request $request)
    {
        $q = $this->applyBranchScope(Expense::with(['project:id,name','property:id,property_number','createdBy:id,name']));
        foreach (['project_id','property_id','category','payment_method'] as $field) {
            if ($request->filled($field)) $q->where($field, $request->input($field));
        }
        if ($request->filled('from')) $q->whereDate('expense_date', '>=', $request->input('from'));
        if ($request->filled('to')) $q->whereDate('expense_date', '<=', $request->input('to'));
        if ($request->filled('search')) {
            $search = $request->input('search');
            $q->where(function ($w) use ($search) {
                $w->where('expense_number','like',"%{$search}%")
                  ->orWhere('description','like',"%{$search}%")
                  ->orWhere('vendor_name','like',"%{$search}%");
            });
        }
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);
        return response()->json($q->latest('expense_date')->latest('id')->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'project_id'=>'nullable|exists:projects,id', 'property_id'=>'nullable|exists:properties,id',
            'category'=>'required|string|max:100', 'description'=>'required|string|max:255',
            'amount'=>'required|numeric|min:0.01', 'expense_date'=>'required|date',
            'payment_method'=>'required|in:cash,bank_transfer,cheque,online,other',
            'reference_number'=>'nullable|string|max:100', 'vendor_name'=>'nullable|string|max:255', 'notes'=>'nullable|string',
        ]);
        $data = $this->validateBranchRefs($data);
        $data['created_by'] = $request->user()->id;
        $data['expense_number'] = 'EXP-'.now()->format('Ym').'-'.strtoupper(Str::random(7));
        $expense = DB::transaction(fn () => Expense::create($data));
        return response()->json(['message'=>'Expense recorded successfully.','expense'=>$expense->load(['project','property','createdBy'])], 201);
    }

    public function show(Expense $expense)
    {
        $this->applyBranchScope(Expense::query())->findOrFail($expense->id);
        return response()->json($expense->load(['project','property','createdBy']));
    }

    public function update(Request $request, Expense $expense)
    {
        $data = $request->validate([
            'project_id'=>'nullable|exists:projects,id', 'property_id'=>'nullable|exists:properties,id',
            'category'=>'required|string|max:100', 'description'=>'required|string|max:255',
            'amount'=>'required|numeric|min:0.01', 'expense_date'=>'required|date',
            'payment_method'=>'required|in:cash,bank_transfer,cheque,online,other',
            'reference_number'=>'nullable|string|max:100', 'vendor_name'=>'nullable|string|max:255', 'notes'=>'nullable|string',
        ]);
        $this->applyBranchScope(Expense::query())->findOrFail($expense->id);
        $data = $this->validateBranchRefs($data);
        $expense->update($data);
        return response()->json(['message'=>'Expense updated successfully.','expense'=>$expense->fresh()->load(['project','property','createdBy'])]);
    }

    public function destroy(Expense $expense)
    {
        $this->applyBranchScope(Expense::query())->findOrFail($expense->id);
        $expense->delete();
        return response()->json(['message'=>'Expense deleted successfully.']);
    }
}
