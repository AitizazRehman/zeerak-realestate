<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run()
    {
        $modules = [
            'dashboard',
            'users',
            'roles',
            'properties',
            'projects',
            'customers',
            'leads',
            'site_visits',
            'sales',
            'installments',
            'payments',
            'expenses',
            'commissions',
            'construction',
            'materials',
            'vendors',
            'contractors',
            'documents',
            'complaints',
            'reports',
            'settings',
        ];

        $actions = [
            'view',
            'create',
            'edit',
            'delete',
        ];

        foreach ($modules as $module) {
            foreach ($actions as $action) {
                Permission::firstOrCreate([
                    'name' => "{$module}.{$action}",
                    'guard_name' => 'web',
                ]);
            }
        }

        $roles = [
            'Super Admin',
            'Admin',
            'Manager',
            'Sales Agent',
            'Accountant',
            'Construction Manager',
            'HR',
            'Customer',
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate([
                'name' => $role,
                'guard_name' => 'web',
            ]);
        }

        $superAdmin = Role::where('name', 'Super Admin')->first();

        $superAdmin->syncPermissions(
            Permission::all()
        );

        $admin = Role::where('name', 'Admin')->first();

        $admin->syncPermissions([
            'dashboard.view',
            'users.view',
            'users.create',
            'users.edit',

            'properties.view',
            'properties.create',
            'properties.edit',
            'properties.delete',

            'projects.view',
            'projects.create',
            'projects.edit',

            'customers.view',
            'customers.create',
            'customers.edit',

            'leads.view',
            'leads.create',
            'leads.edit',

            'sales.view',
            'sales.create',
            'sales.edit',

            'payments.view',
            'payments.create',

            'expenses.view',
            'expenses.create',

            'reports.view',
            'settings.view',
            'settings.edit',
        ]);
    }
}