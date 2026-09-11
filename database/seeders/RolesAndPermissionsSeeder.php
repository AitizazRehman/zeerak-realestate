<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run()
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $modules = ['dashboard','users','roles','properties','projects','customers','leads','site_visits','sales','installments','payments','expenses','commissions','construction','materials','vendors','contractors','documents','complaints','reports','settings'];
        $actions = ['view','create','edit','delete'];

        foreach ($modules as $module) {
            foreach ($actions as $action) {
                Permission::firstOrCreate(['name' => "{$module}.{$action}", 'guard_name' => 'web']);
            }
        }

        $roleNames = ['Super Admin','Admin','Manager','Sales Agent','Accountant','Construction Manager','HR','Customer'];
        foreach ($roleNames as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        $all = Permission::where('guard_name', 'web')->get();
        Role::where('name', 'Super Admin')->first()->syncPermissions($all);

        $matrix = [
            'Admin' => [
                'dashboard.view','users.view','users.create','users.edit','users.delete',
                'roles.view','roles.create','roles.edit','roles.delete',
                'properties.view','properties.create','properties.edit','properties.delete',
                'projects.view','projects.create','projects.edit','projects.delete',
                'customers.view','customers.create','customers.edit','customers.delete',
                'leads.view','leads.create','leads.edit','leads.delete',
                'site_visits.view','site_visits.create','site_visits.edit','site_visits.delete',
                'sales.view','sales.create','sales.edit','sales.delete',
                'installments.view','installments.create','installments.edit',
                'payments.view','payments.create',
                'expenses.view','expenses.create','expenses.edit',
                'commissions.view','commissions.create','commissions.edit',
                'construction.view','construction.create','construction.edit',
                'materials.view','materials.create','materials.edit',
                'vendors.view','vendors.create','vendors.edit',
                'contractors.view','contractors.create','contractors.edit',
                'documents.view','documents.create','documents.edit',
                'complaints.view','complaints.create','complaints.edit',
                'reports.view','settings.view','settings.edit',
            ],
            'Manager' => [
                'dashboard.view','properties.view','properties.create','properties.edit',
                'projects.view','projects.create','projects.edit',
                'customers.view','customers.create','customers.edit',
                'leads.view','leads.create','leads.edit',
                'site_visits.view','site_visits.create','site_visits.edit',
                'sales.view','sales.create','sales.edit',
                'installments.view','installments.create','installments.edit',
                'payments.view','commissions.view','commissions.create','commissions.edit',
                'construction.view','construction.create','construction.edit',
                'materials.view','materials.create','materials.edit',
                'vendors.view','vendors.create','vendors.edit',
                'contractors.view','contractors.create','contractors.edit',
                'documents.view','documents.create','documents.edit',
                'complaints.view','complaints.create','complaints.edit',
                'reports.view',
            ],
            'Sales Agent' => [
                'dashboard.view','properties.view','projects.view',
                'customers.view','customers.create','customers.edit',
                'leads.view','leads.create','leads.edit',
                'site_visits.view','site_visits.create','site_visits.edit',
                'sales.view','sales.create','sales.edit',
                'installments.view','payments.view','payments.create',
                'commissions.view','reports.view',
            ],
            'Accountant' => [
                'dashboard.view','properties.view','projects.view','customers.view',
                'sales.view','installments.view','installments.edit',
                'payments.view','payments.create',
                'commissions.view','commissions.edit',
                'expenses.view','expenses.create','expenses.edit',
                'reports.view','documents.view',
            ],
            'Construction Manager' => [
                'dashboard.view','projects.view','projects.create','projects.edit',
                'properties.view','construction.view','construction.create','construction.edit',
                'materials.view','materials.create','materials.edit','materials.delete',
                'vendors.view','vendors.create','vendors.edit',
                'contractors.view','contractors.create','contractors.edit',
                'documents.view','documents.create','documents.edit','reports.view',
            ],
            'HR' => [
                'dashboard.view','users.view','roles.view','reports.view',
            ],
            'Customer' => [
                'dashboard.view','properties.view','projects.view','documents.view',
                'complaints.view','complaints.create',
            ],
        ];

        foreach ($matrix as $roleName => $permissions) {
            Role::where('name', $roleName)->first()->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
