<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function salesAgents(Request $request)
    {
        $query = User::query()
            ->whereHas('roles', function ($q) {
                $q->where('name', 'Sales Agent');
            })
            ->select(['id', 'name', 'email', 'branch_id'])
            ->with('branch:id,name')
            ->orderBy('name');

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
        return response()->json([
            'success' => true,
            'data' => Role::where('guard_name', 'web')->orderBy('name')->get(['id', 'name'])
        ]);
    }

    public function index(Request $request)
    {
        $query = User::with(['roles:id,name', 'branch:id,name'])
            ->select(['id', 'name', 'email', 'branch_id', 'created_at']);
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
        ]);
        $role = $data['role'] ?? null;
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
        ]);
        $roleProvided = array_key_exists('role', $data);
        $role = $data['role'] ?? null;
        unset($data['role']);
        if (!empty($data['password'])) $data['password'] = Hash::make($data['password']); else unset($data['password']);
        $user->update($data);
        if ($roleProvided) $role ? $user->syncRoles([$role]) : $user->syncRoles([]);
        return response()->json(['success' => true, 'message' => 'User updated successfully.', 'data' => $user->fresh()->load(['roles:id,name', 'branch:id,name'])]);
    }

    public function destroy(Request $request, User $user)
    {
        if ($request->user()->is($user)) return response()->json(['success' => false, 'message' => 'You cannot delete your own account.'], 422);
        $user->tokens()->delete();
        $user->delete();
        return response()->json(['success' => true, 'message' => 'User deleted successfully.']);
    }
}
