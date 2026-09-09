<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $q = Expense::with(['project:id,name','property:id,property_number','createdBy:id,name']);
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
        $data['created_by'] = $request->user()->id;
        $data['expense_number'] = 'EXP-'.now()->format('Ym').'-'.strtoupper(Str::random(7));
        $expense = DB::transaction(fn () => Expense::create($data));
        return response()->json(['message'=>'Expense recorded successfully.','expense'=>$expense->load(['project','property','createdBy'])], 201);
    }

    public function show(Expense $expense)
    {
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
        $expense->update($data);
        return response()->json(['message'=>'Expense updated successfully.','expense'=>$expense->fresh()->load(['project','property','createdBy'])]);
    }

    public function destroy(Expense $expense)
    {
        $expense->delete();
        return response()->json(['message'=>'Expense deleted successfully.']);
    }
}
