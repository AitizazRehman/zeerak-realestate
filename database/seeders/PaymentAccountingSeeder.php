<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use Illuminate\Database\Seeder;

class PaymentAccountingSeeder extends Seeder
{
    public function run()
    {
        $parent = ChartOfAccount::where('code', '1100')->where('is_system', true)->first();
        if (!$parent) {
            throw new \RuntimeException('Seed the Accounting Foundation before payment accounts.');
        }
        foreach (['1101' => 'Cash in Hand', '1102' => 'Bank Receipts Clearing'] as $code => $name) {
            // Preserve any existing account with this code, including archived accounts.
            if (ChartOfAccount::withTrashed()->where('code', $code)->exists()) continue;
            ChartOfAccount::create([
                'parent_id' => $parent->id, 'code' => $code, 'name' => $name,
                'account_type' => 'asset', 'normal_balance' => 'debit',
                'is_control_account' => false, 'allow_manual_posting' => true,
                'is_system' => true, 'is_active' => true,
            ]);
        }
    }
}
