<?php

namespace Tests\Support;

use App\Models\{ChartOfAccount, FiscalYear, User};
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class AccountingTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        foreach ([
            'users' => 'id INTEGER PRIMARY KEY, name TEXT',
            'projects' => 'id INTEGER PRIMARY KEY, branch_id INTEGER, deleted_at TEXT',
            'customers' => 'id INTEGER PRIMARY KEY, name TEXT, deleted_at TEXT',
            'properties' => 'id INTEGER PRIMARY KEY, project_id INTEGER, status TEXT, price DECIMAL(15,2), discount DECIMAL(15,2), deleted_at TEXT',
            'bookings' => 'id INTEGER PRIMARY KEY, customer_id INTEGER, property_id INTEGER, booking_number TEXT, booking_date TEXT, status TEXT, final_price DECIMAL(15,2), paid_amount DECIMAL(15,2), remaining_amount DECIMAL(15,2), created_at TEXT, updated_at TEXT, deleted_at TEXT',
            'installment_plans' => 'id INTEGER PRIMARY KEY, booking_id INTEGER, status TEXT, deleted_at TEXT',
            'installments' => 'id INTEGER PRIMARY KEY, booking_id INTEGER, installment_plan_id INTEGER, remaining_amount DECIMAL(15,2)',
            'financial_audits' => 'id INTEGER PRIMARY KEY, entity_type TEXT, entity_id INTEGER, branch_id INTEGER, action TEXT, user_id INTEGER, before_data TEXT, after_data TEXT, reason TEXT, created_at TEXT, updated_at TEXT',
        ] as $table => $columns) DB::statement('CREATE TABLE '.$table.' ('.$columns.')');
        $paymentMigration = require database_path('migrations/2026_09_09_175000_create_payments_table.php');
        $paymentMigration->up();
        DB::statement('ALTER TABLE payments ADD COLUMN reversed_at TEXT');
        DB::statement('ALTER TABLE payments ADD COLUMN reversed_by INTEGER');
        DB::statement('ALTER TABLE payments ADD COLUMN reversal_reason TEXT');
        foreach ([
            '2026_09_26_160000_create_chart_of_accounts_table.php' => 'CreateChartOfAccountsTable',
            '2026_09_26_170000_create_fiscal_years_and_accounting_periods.php' => 'CreateFiscalYearsAndAccountingPeriods',
            '2026_09_26_180000_create_journal_entries_and_lines.php' => 'CreateJournalEntriesAndLines',
            '2026_09_26_190000_add_cash_bank_account_to_payments.php' => 'AddCashBankAccountToPayments',
        ] as $file => $class) {
            require_once database_path('migrations/'.$file);
            (new $class)->up();
        }
        DB::table('users')->insert(['id' => 1, 'name' => 'Accountant']);
        DB::table('projects')->insert(['id' => 1, 'branch_id' => 1]);
        DB::table('customers')->insert(['id' => 1, 'name' => 'Customer']);
        DB::table('properties')->insert(['id' => 1, 'project_id' => 1, 'status' => 'booked', 'price' => 1000, 'discount' => 0]);
        DB::table('bookings')->insert(['id' => 1, 'property_id' => 1, 'customer_id' => 1, 'booking_number' => 'BK-1', 'booking_date' => '2026-01-01', 'status' => 'confirmed', 'final_price' => 1000, 'paid_amount' => 0, 'remaining_amount' => 1000]);
        $root = ChartOfAccount::create(['code' => '1100', 'name' => 'Cash & Bank', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_control_account' => true, 'is_system' => true]);
        ChartOfAccount::create(['parent_id' => $root->id, 'code' => '1101', 'name' => 'Cash', 'account_type' => 'asset', 'normal_balance' => 'debit']);
        ChartOfAccount::create(['code' => '2300', 'name' => 'Customer Advances', 'account_type' => 'liability', 'normal_balance' => 'credit', 'is_control_account' => true, 'is_system' => true]);
        $year = FiscalYear::create(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $year->periods()->create(['name' => 'September', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);
        $this->actingAs(User::find(1));
        \Carbon\Carbon::setTestNow('2026-09-26 12:00:00');
    }

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

}
