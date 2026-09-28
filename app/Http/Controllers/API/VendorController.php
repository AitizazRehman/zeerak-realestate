<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Branch;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VendorController extends Controller
{
    use ChecksBranchAccess;

    private function scopeBranch($query)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;

            if (!$branchId) {
                abort(403, 'Your user account is not assigned to a branch.');
            }

            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    public function index(Request $request)
    {
        $query = $this->scopeBranch(
            Vendor::with('branch:id,name,code')
                ->withCount('bills')
        );

        if ($request->filled('branch_id') && $this->canAccessAllBranches()) {
            $query->where('branch_id', (int) $request->branch_id);
        }

        if ($request->filled('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        if ($request->filled('search')) {
            $search = trim($request->search);

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('vendor_number', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('tax_number', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->orderBy('name')
                ->paginate(min(max((int) $request->get('per_page', 25), 1), 100))
        );
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['branch_id'] = $this->resolveBranch($data['branch_id'] ?? null);

        $this->assertUniqueIdentity($data['branch_id'], $data['name'], $data['phone'] ?? null, $data['tax_number'] ?? null);

        do {
            $number = 'VND-'.now()->format('Ym').'-'.strtoupper(Str::random(8));
        } while (Vendor::withTrashed()->where('vendor_number', $number)->exists());

        $vendor = Vendor::create(array_merge($data, [
            'vendor_number' => $number,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ]));

        return response()->json([
            'message' => 'Vendor created successfully.',
            'vendor' => $vendor->load('branch:id,name,code'),
        ], 201);
    }

    public function update(Request $request, Vendor $vendor)
    {
        $vendor = $this->scopeBranch(Vendor::query())->findOrFail($vendor->id);
        $data = $this->validated($request, true);

        if ($this->canAccessAllBranches() && array_key_exists('branch_id', $data)) {
            $data['branch_id'] = $this->resolveBranch($data['branch_id']);
        } else {
            unset($data['branch_id']);
        }

        $branchId = $data['branch_id'] ?? $vendor->branch_id;
        $name = $data['name'] ?? $vendor->name;
        $phone = array_key_exists('phone', $data) ? $data['phone'] : $vendor->phone;
        $taxNumber = array_key_exists('tax_number', $data) ? $data['tax_number'] : $vendor->tax_number;

        $this->assertUniqueIdentity($branchId, $name, $phone, $taxNumber, $vendor->id);

        if (isset($data['branch_id']) && (int) $data['branch_id'] !== (int) $vendor->branch_id && $vendor->bills()->exists()) {
            throw ValidationException::withMessages([
                'branch_id' => ['A vendor with bill history cannot be moved to another branch.'],
            ]);
        }

        if (array_key_exists('is_active', $data) && !$data['is_active'] &&
            $vendor->bills()->whereIn('status', ['posted','partial'])->where('remaining_amount', '>', 0)->exists()) {
            throw ValidationException::withMessages([
                'is_active' => ['Settle or cancel outstanding vendor bills before deactivating this vendor.'],
            ]);
        }

        $vendor->update($data);

        return response()->json([
            'message' => 'Vendor updated successfully.',
            'vendor' => $vendor->fresh()->load('branch:id,name,code'),
        ]);
    }

    private function validated(Request $request, $partial = false)
    {
        $required = $partial ? 'sometimes' : 'required';
        $branchRule = $partial
            ? ['sometimes','nullable','integer','exists:branches,id']
            : ($this->canAccessAllBranches()
                ? ['required','integer','exists:branches,id']
                : ['nullable','integer','exists:branches,id']);

        return $request->validate([
            'branch_id' => $branchRule,
            'name' => [$required,'string','max:255'],
            'contact_person' => ['nullable','string','max:255'],
            'phone' => ['nullable','string','max:50'],
            'email' => ['nullable','email','max:255'],
            'tax_number' => ['nullable','string','max:100'],
            'address' => ['nullable','string','max:500'],
            'city' => ['nullable','string','max:100'],
            'bank_name' => ['nullable','string','max:255'],
            'account_title' => ['nullable','string','max:255'],
            'account_number' => ['nullable','string','max:100'],
            'iban' => ['nullable','string','max:100'],
            'payment_terms_days' => ['nullable','integer','min:0','max:365'],
            'is_active' => ['nullable','boolean'],
            'notes' => ['nullable','string','max:5000'],
        ]);
    }

    private function resolveBranch($branchId)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;

            if (!$branchId) {
                abort(403, 'Your user account is not assigned to a branch.');
            }
        }

        $branch = Branch::where('is_active', true)->find($branchId);

        if (!$branch) {
            throw ValidationException::withMessages([
                'branch_id' => ['Choose an active branch.'],
            ]);
        }

        return $branch->id;
    }

    private function assertUniqueIdentity($branchId, $name, $phone, $taxNumber, $ignoreId = null)
    {
        $query = Vendor::query()
            ->where('branch_id', $branchId)
            ->when($ignoreId, function ($q) use ($ignoreId) {
                $q->where('id', '!=', $ignoreId);
            })
            ->where(function ($q) use ($name, $phone, $taxNumber) {
                $q->whereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim($name))]);

                if ($phone) {
                    $q->orWhere('phone', trim($phone));
                }

                if ($taxNumber) {
                    $q->orWhere('tax_number', trim($taxNumber));
                }
            });

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => ['A vendor with the same name, phone, or tax number already exists in this branch.'],
            ]);
        }
    }
}
