<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\Branch;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
        ]);
        $branch = Branch::firstOrCreate(
            [
                'code' => 'RWP-HO',
            ],
            [
                'name' => 'Zeerak Head Office',
                'phone' => '03452117099',
                'city' => 'Rawalpindi',
                'province' => 'Punjab',
                'is_head_office' => true,
                'is_active' => true,
            ]
        );

        $user = User::firstOrCreate(
            [
                'email' => 'admin@zeerakrealestate.com',
            ],
            [
                'name' => 'Super Administrator',
                'password' => Hash::make('ChangeMe@123'),
                'branch_id' => $branch->id,
            ]
        );

        $user->assignRole('Super Admin');
    }
}
