<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    use ChecksBranchAccess;
    public function salesAgents(Request $request)
    {
        $query = User::query()
            ->where('is_active', true)
            ->whereHas('roles', function ($q) {
                $q->where('name', 'Sales Agent');
            })
            ->select(['id', 'name', 'email', 'branch_id'])
            ->with('branch:id,name')
            ->orderBy('name');

        if (!$this->canAccessAllBranches()) {
            $query->where('branch_id', auth()->user()->branch_id);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    public function roles()
    {
        $query = Role::where('guard_name', 'web');
        if (!$this->canAccessAllBranches()) {
            $query->whereNotIn('name', ['Super Admin', 'Admin']);
        }
        return response()->json([
            'success' => true,
            'data' => $query->orderBy('name')->get(['id', 'name'])
        ]);
    }

    private function protectRoleAssignment($role)
    {
        if (!$this->canAccessAllBranches() && in_array($role, ['Super Admin', 'Admin'], true)) {
            abort(403, 'You are not authorized to assign this role.');
        }
    }

    public function index(Request $request)
    {
        $query = User::with(['roles:id,name', 'branch:id,name'])
            ->select(['id', 'name', 'email', 'branch_id', 'is_active', 'created_at']);
        if (!$this->canAccessAllBranches()) $query->where('branch_id', auth()->user()->branch_id);
        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }
        if ($request->filled('role')) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('name', $request->role);
            });
        }
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);
        return response()->json($query->latest()->paginate($perPage));
    }

    public function show(User $user)
    {
        if (!$this->canAccessAllBranches()) $this->ensureBranchAccess($user->branch_id);
        return response()->json(['success' => true, 'data' => $user->load(['roles:id,name', 'branch:id,name'])]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'role' => ['nullable', 'string', 'exists:roles,name'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $role = $data['role'] ?? null;
        $this->protectRoleAssignment($role);
        if (!$this->canAccessAllBranches()) {
            $data['branch_id'] = auth()->user()->branch_id;
        }
        unset($data['role']);
        $data['password'] = Hash::make($data['password']);
        $user = User::create($data);
        if ($role) $user->assignRole($role);
        return response()->json(['success' => true, 'message' => 'User created successfully.', 'data' => $user->load(['roles:id,name', 'branch:id,name'])], 201);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'role' => ['nullable', 'string', 'exists:roles,name'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        if (!$this->canAccessAllBranches()) {
            $this->ensureBranchAccess($user->branch_id);
        }
        $roleProvided = array_key_exists('role', $data);
        $role = $data['role'] ?? null;
        $this->protectRoleAssignment($role);
        if (!$this->canAccessAllBranches()) {
            $data['branch_id'] = auth()->user()->branch_id;
            if ($user->hasAnyRole(['Super Admin', 'Admin'])) abort(403, 'You are not authorized to modify an administrator account.');
        }
        unset($data['role']);
        if (!empty($data['password'])) $data['password'] = Hash::make($data['password']); else unset($data['password']);
        if (array_key_exists('is_active', $data) && !$data['is_active'] && auth()->user()->is($user)) abort(422, 'You cannot deactivate your own account.');
        $deactivating = array_key_exists('is_active', $data) && !$data['is_active'] && $user->is_active;
        $user->update($data);
        if ($deactivating) $user->tokens()->delete();
        if ($roleProvided) $role ? $user->syncRoles([$role]) : $user->syncRoles([]);
        return response()->json(['success' => true, 'message' => 'User updated successfully.', 'data' => $user->fresh()->load(['roles:id,name', 'branch:id,name'])]);
    }

    public function destroy(Request $request, User $user)
    {
        if ($request->user()->is($user)) return response()->json(['success' => false, 'message' => 'You cannot delete your own account.'], 422);
        if (!$this->canAccessAllBranches()) {
            $this->ensureBranchAccess($user->branch_id);
            if ($user->hasAnyRole(['Super Admin', 'Admin'])) abort(403, 'You are not authorized to delete an administrator account.');
        }
        $user->tokens()->delete();
        $user->delete();
        return response()->json(['success' => true, 'message' => 'User deleted successfully.']);
    }
}
