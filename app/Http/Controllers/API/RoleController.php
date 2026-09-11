<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * Return roles with their permissions and the number of assigned users.
     *
     * Spatie's Role model does not provide a users() Eloquent relationship,
     * so user counts are calculated through the User model's role scope.
     */
    public function index()
    {
        $roles = Role::where('guard_name', 'web')
            ->with(['permissions:id,name'])
            ->orderBy('name')
            ->get(['id', 'name', 'guard_name']);

        $roles->each(function ($role) {
            $role->users_count = User::role($role->name)->count();
        });

        return response()->json(['success' => true, 'data' => $roles]);
    }

    public function permissions()
    {
        $permissions = Permission::where('guard_name', 'web')
            ->orderBy('name')
            ->get(['id', 'name', 'guard_name']);

        return response()->json(['success' => true, 'data' => $permissions]);
    }

    public function show(Role $role)
    {
        abort_unless($role->guard_name === 'web', 404);

        $role->load('permissions:id,name');
        $role->users_count = User::role($role->name)->count();

        return response()->json([
            'success' => true,
            'data' => $role,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:roles,name'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);

        $role = Role::create([
            'name' => trim($data['name']),
            'guard_name' => 'web',
        ]);

        $role->syncPermissions(
            Permission::whereIn('id', $data['permissions'] ?? [])->get()
        );

        $role->load('permissions:id,name');
        $role->users_count = User::role($role->name)->count();

        return response()->json([
            'success' => true,
            'message' => 'Role created successfully.',
            'data' => $role,
        ], 201);
    }

    public function update(Request $request, Role $role)
    {
        abort_unless($role->guard_name === 'web', 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:roles,name,' . $role->id],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);

        $role->update(['name' => trim($data['name'])]);
        $role->syncPermissions(
            Permission::whereIn('id', $data['permissions'] ?? [])->get()
        );

        $role->refresh();
        $role->load('permissions:id,name');
        $role->users_count = User::role($role->name)->count();

        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully.',
            'data' => $role,
        ]);
    }

    public function destroy(Role $role)
    {
        abort_unless($role->guard_name === 'web', 404);

        if ($role->name === 'Super Admin') {
            return response()->json([
                'success' => false,
                'message' => 'The Super Admin role cannot be deleted.',
            ], 422);
        }

        if (User::role($role->name)->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'This role is assigned to users and cannot be deleted.',
            ], 422);
        }

        $role->delete();

        return response()->json([
            'success' => true,
            'message' => 'Role deleted successfully.',
        ]);
    }
}
