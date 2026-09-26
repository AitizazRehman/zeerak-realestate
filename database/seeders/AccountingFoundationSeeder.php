<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AccountingFoundationSeeder extends Seeder
{
    public function run()
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['view','create','edit','delete'] as $action) {
            Permission::firstOrCreate([
                'name' => 'accounting.'.$action,
                'guard_name' => 'web',
            ]);
        }

        $rolePermissions = [
            'Super Admin' => ['accounting.view','accounting.create','accounting.edit','accounting.delete'],
            'Admin' => ['accounting.view','accounting.create','accounting.edit','accounting.delete'],
            'Accountant' => ['accounting.view','accounting.create','accounting.edit','accounting.delete'],
            'Manager' => ['accounting.view'],
        ];

        foreach ($rolePermissions as $roleName => $permissions) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

            if ($role) {
                $role->givePermissionTo($permissions);
            }
        }

        $accounts = [
            ['code'=>'1000','name'=>'Assets','type'=>'asset','parent'=>null,'control'=>false,'manual'=>false],
            ['code'=>'1100','name'=>'Cash & Bank','type'=>'asset','parent'=>'1000','control'=>true,'manual'=>false],
            ['code'=>'1200','name'=>'Accounts Receivable','type'=>'asset','parent'=>'1000','control'=>true,'manual'=>false],
            ['code'=>'1300','name'=>'Property Inventory','type'=>'asset','parent'=>'1000','control'=>true,'manual'=>false],

            ['code'=>'2000','name'=>'Liabilities','type'=>'liability','parent'=>null,'control'=>false,'manual'=>false],
            ['code'=>'2100','name'=>'Accounts Payable','type'=>'liability','parent'=>'2000','control'=>true,'manual'=>false],
            ['code'=>'2200','name'=>'Commission Payable','type'=>'liability','parent'=>'2000','control'=>true,'manual'=>false],
            ['code'=>'2300','name'=>'Customer Advances','type'=>'liability','parent'=>'2000','control'=>true,'manual'=>false],

            ['code'=>'3000','name'=>'Equity','type'=>'equity','parent'=>null,'control'=>false,'manual'=>false],
            ['code'=>'3100','name'=>'Capital','type'=>'equity','parent'=>'3000','control'=>false,'manual'=>true],
            ['code'=>'3200','name'=>'Retained Earnings','type'=>'equity','parent'=>'3000','control'=>true,'manual'=>false],

            ['code'=>'4000','name'=>'Revenue','type'=>'revenue','parent'=>null,'control'=>false,'manual'=>false],
            ['code'=>'4100','name'=>'Property Sales','type'=>'revenue','parent'=>'4000','control'=>false,'manual'=>true],
            ['code'=>'4200','name'=>'Other Income','type'=>'revenue','parent'=>'4000','control'=>false,'manual'=>true],

            ['code'=>'5000','name'=>'Cost of Sales','type'=>'cost_of_sales','parent'=>null,'control'=>false,'manual'=>false],
            ['code'=>'5100','name'=>'Land Cost','type'=>'cost_of_sales','parent'=>'5000','control'=>false,'manual'=>true],
            ['code'=>'5200','name'=>'Construction Cost','type'=>'cost_of_sales','parent'=>'5000','control'=>false,'manual'=>true],

            ['code'=>'6000','name'=>'Operating Expenses','type'=>'expense','parent'=>null,'control'=>false,'manual'=>false],
            ['code'=>'6100','name'=>'Salaries & Benefits','type'=>'expense','parent'=>'6000','control'=>false,'manual'=>true],
            ['code'=>'6200','name'=>'Marketing Expense','type'=>'expense','parent'=>'6000','control'=>false,'manual'=>true],
            ['code'=>'6300','name'=>'Utilities Expense','type'=>'expense','parent'=>'6000','control'=>false,'manual'=>true],
            ['code'=>'6400','name'=>'Office Expense','type'=>'expense','parent'=>'6000','control'=>false,'manual'=>true],
            ['code'=>'6500','name'=>'Sales Commission Expense','type'=>'expense','parent'=>'6000','control'=>false,'manual'=>true],
            ['code'=>'6600','name'=>'Bank Charges','type'=>'expense','parent'=>'6000','control'=>false,'manual'=>true],
        ];

        $ids = [];

        foreach ($accounts as $definition) {
            $parentId = $definition['parent'] && isset($ids[$definition['parent']])
                ? $ids[$definition['parent']]
                : null;

            $normalBalance = in_array($definition['type'], ['asset','cost_of_sales','expense'], true)
                ? 'debit'
                : 'credit';

            $account = ChartOfAccount::withTrashed()->firstOrNew(['code' => $definition['code']]);

            $account->fill([
                'parent_id' => $parentId,
                'name' => $definition['name'],
                'account_type' => $definition['type'],
                'normal_balance' => $normalBalance,
                'is_control_account' => $definition['control'],
                'allow_manual_posting' => $definition['manual'],
                'is_system' => true,
                'is_active' => true,
                'description' => null,
            ]);

            if ($account->trashed()) {
                $account->restore();
            }

            $account->save();
            $ids[$definition['code']] = $account->id;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
