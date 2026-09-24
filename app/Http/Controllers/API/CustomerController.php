<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\Branch;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Installment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    use ChecksBranchAccess;

    private function scopeBranch($query)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;
            $query->where(function ($customerQuery) use ($branchId) {
                $customerQuery->where('branch_id', $branchId)
                    ->orWhereHas('bookings.property.project', function ($q) use ($branchId) {
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
        if ($customer->branch_id && (int) $customer->branch_id !== (int) $branchId) {
            abort(403, 'This customer belongs to another branch and can only be modified by an administrator.');
        }

        $hasOtherBranchBookings = $customer->bookings()
            ->whereHas('property.project', function ($q) use ($branchId) {
                $q->where('branch_id', '!=', $branchId);
            })->exists();

        if ($hasOtherBranchBookings) {
            abort(403, 'This customer is shared with another branch and can only be modified by an administrator.');
        }
    }

    private function resolveMutationBranch(array &$data, Request $request, Customer $customer = null)
    {
        if (!$this->canAccessAllBranches()) {
            if (!$request->user()->branch_id) {
                abort(422, 'Your user account must be assigned to a branch before managing customers.');
            }

            $data['branch_id'] = $customer && $customer->branch_id
                ? $customer->branch_id
                : $request->user()->branch_id;

            return (int) $data['branch_id'];
        }

        $branchId = isset($data['branch_id']) && $data['branch_id']
            ? (int) $data['branch_id']
            : ($customer && $customer->branch_id ? (int) $customer->branch_id : null);

        if (!$branchId) {
            throw ValidationException::withMessages([
                'branch_id' => ['Select an active branch for this customer.'],
            ]);
        }

        $branch = Branch::where('is_active', true)->find($branchId);

        if (!$branch) {
            throw ValidationException::withMessages([
                'branch_id' => ['The selected branch is inactive or unavailable.'],
            ]);
        }

        $data['branch_id'] = $branch->id;

        return (int) $branch->id;
    }

    private function assertNoDuplicateIdentity($branchId, $phone, $email, $ignoreId = null)
    {
        $query = Customer::query()
            ->matchingIdentity($phone, $email)
            ->where(function ($branchQuery) use ($branchId) {
                $branchQuery->where('branch_id', $branchId)
                    ->orWhereNull('branch_id');
            });

        if ($ignoreId) {
            $query->where('id', '!=', (int) $ignoreId);
        }

        $duplicate = $query->orderByDesc('id')->first();

        if (!$duplicate) {
            return;
        }

        $messages = [];
        $normalizedPhone = Customer::normalizedPhone($phone);
        $duplicatePhone = Customer::normalizedPhone($duplicate->phone);
        $normalizedEmail = strtolower(trim((string) $email));
        $duplicateEmail = strtolower(trim((string) $duplicate->email));
        $reference = $duplicate->customer_number ?: '#'.$duplicate->id;

        if ($normalizedPhone !== '' && $normalizedPhone === $duplicatePhone) {
            $messages['phone'] = ['This phone already belongs to customer '.$reference.'.'];
        }

        if ($normalizedEmail !== '' && $normalizedEmail === $duplicateEmail) {
            $messages['email'] = ['This email already belongs to customer '.$reference.'.'];
        }

        if (!$messages) {
            $messages['phone'] = ['A customer with the same phone or email already exists ('.$reference.').'];
        }

        throw ValidationException::withMessages($messages);
    }

    public function index(Request $request)
    {
        $query = $this->scopeBranch(Customer::with('branch:id,name'));
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
        $data = $request->validated();
        $branchId = $this->resolveMutationBranch($data, $request);
        $this->assertNoDuplicateIdentity($branchId, $data['phone'], isset($data['email']) ? $data['email'] : null);

        $customer = DB::transaction(function () use ($data) {
            return Customer::create(array_merge($data, [
                'customer_number' => $this->generateCustomerNumber(),
            ]));
        });

        return response()->json([
            'message' => 'Customer created successfully.',
            'customer' => $customer->load('branch:id,name'),
        ], 201);
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
        return response()->json($customer->load(['branch:id,name', 'bookings.property.project', 'payments']));
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

        $data = $request->validated();
        $branchId = $this->resolveMutationBranch($data, $request, $customer);
        $this->assertNoDuplicateIdentity(
            $branchId,
            $data['phone'],
            isset($data['email']) ? $data['email'] : null,
            $customer->id
        );

        $customer->update($data);

        return response()->json([
            'message' => 'Customer updated successfully.',
            'customer' => $customer->fresh()->load('branch:id,name'),
        ]);
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
