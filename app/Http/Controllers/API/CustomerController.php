<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Installment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    use ChecksBranchAccess;

    private function scopeBranch($query)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;
            $query->where(function ($customerQuery) use ($branchId) {
                $customerQuery->whereHas('bookings.property.project', function ($q) use ($branchId) {
                    $q->where('branch_id', $branchId);
                })->orWhereHas('leads', function ($lead) use ($branchId) {
                    $lead->whereHas('project', function ($project) use ($branchId) {
                        $project->where('branch_id', $branchId);
                    })->orWhere(function ($fallback) use ($branchId) {
                        $fallback->whereNull('project_id')
                            ->whereHas('assignee', function ($user) use ($branchId) {
                                $user->where('branch_id', $branchId);
                            });
                    });
                });
            });
        }
        return $query;
    }

    private function ensureCustomerAccess(Customer $customer)
    {
        if ($this->canAccessAllBranches()) return;
        $this->scopeBranch(Customer::query())->findOrFail($customer->id);
    }
    private function ensureCustomerMutationAccess(Customer $customer)
    {
        if ($this->canAccessAllBranches()) return;

        $branchId = auth()->user()->branch_id;
        $hasOtherBranchBookings = $customer->bookings()
            ->whereHas('property.project', function ($q) use ($branchId) {
                $q->where('branch_id', '!=', $branchId);
            })->exists();

        if ($hasOtherBranchBookings) {
            abort(403, 'This customer is shared with another branch and can only be modified by an administrator.');
        }
    }

    public function index(Request $request)
    {
        $query = $this->scopeBranch(Customer::query());
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('cnic', 'like', "%{$search}%")
                  ->orWhere('customer_number', 'like', "%{$search}%");
            });
        }
        if ($request->has('is_active')) $query->where('is_active', $request->boolean('is_active'));
        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);
        return response()->json($query->latest()->paginate($perPage));
    }

    public function store(StoreCustomerRequest $request)
    {
        $customer = Customer::create(array_merge($request->validated(), [
            'customer_number' => $this->generateCustomerNumber(),
        ]));
        return response()->json(['message' => 'Customer created successfully.', 'customer' => $customer], 201);
    }

    private function generateCustomerNumber()
    {
        do {
            $number = 'CUS-' . now()->format('Ym') . '-' . strtoupper(Str::random(10));
        } while (Customer::withTrashed()->where('customer_number', $number)->exists());

        return $number;
    }

    public function show(Customer $customer)
    {
        $this->ensureCustomerAccess($customer);
        return response()->json($customer->load(['bookings.property.project', 'payments']));
    }

    public function ledger(Customer $customer)
    {
        $this->ensureCustomerAccess($customer);
        $branchId = auth()->user()->branch_id;
        $bookings = Booking::with(['property.project','installments'])->where('customer_id',$customer->id)->when(!$this->canAccessAllBranches(), function($q) use ($branchId){$q->whereHas('property.project', function($p) use ($branchId){$p->where('branch_id',$branchId);});})->whereNotIn('status',['cancelled'])->orderBy('booking_date')->get();
        $payments = Payment::where('customer_id',$customer->id)->when(!$this->canAccessAllBranches(), function($q) use ($branchId){$q->whereHas('booking.property.project', function($p) use ($branchId){$p->where('branch_id',$branchId);});})->where('status','verified')->orderBy('payment_date')->get();
        $installments = Installment::with('booking:id,booking_number,customer_id')->whereHas('booking', function ($q) use ($customer, $branchId) { $q->where('customer_id',$customer->id); if(!$this->canAccessAllBranches()) $q->whereHas('property.project', function($p) use ($branchId){$p->where('branch_id',$branchId);}); })->orderBy('due_date')->get();
        return response()->json([
            'customer'=>$customer,
            'summary'=>[
                'booked_value'=>(float)$bookings->sum('final_price'),
                'paid'=>(float)$payments->sum('amount'),
                'outstanding'=>(float)$bookings->sum('remaining_amount'),
                'overdue'=>(float)$installments->filter(function($x){return $x->remaining_amount>0 && $x->due_date->lt(now()->startOfDay());})->sum('remaining_amount'),
            ],
            'bookings'=>$bookings,'payments'=>$payments,'installments'=>$installments,
        ]);
    }

    public function statement(Customer $customer)
    {
        $data = $this->ledger($customer)->getData(true);
        $pdf = Pdf::loadView('customers.statement', $data)->setPaper('a4');
        return $pdf->download('customer-statement-'.$customer->customer_number.'.pdf');
    }

    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        $this->ensureCustomerAccess($customer);
        $this->ensureCustomerMutationAccess($customer);
        $customer->update($request->validated());
        return response()->json(['message' => 'Customer updated successfully.', 'customer' => $customer->fresh()]);
    }

    public function destroy(Customer $customer)
    {
        $this->ensureCustomerAccess($customer);
        $this->ensureCustomerMutationAccess($customer);

        if ($customer->bookings()->exists() || $customer->payments()->exists()) {
            abort(422, 'Customers with booking or payment history cannot be deleted. Deactivate the customer instead.');
        }

        $customer->delete();
        return response()->json(['message' => 'Customer deleted successfully.']);
    }
}
