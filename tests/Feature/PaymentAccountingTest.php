<?php

namespace Tests\Feature;

use App\Http\Controllers\API\PaymentController;
use App\Http\Controllers\API\JournalEntryController;
use App\Models\{AccountingPeriod, Booking, ChartOfAccount, FiscalYear, JournalEntry, Payment, User};
use App\Services\{AccountingPeriodService, PaymentAccountingService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentAccountingTest extends TestCase
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

    private function controller()
    {
        return new class extends PaymentController {
            protected function canAccessAllBranches() { return true; }
        };
    }

    private function request(array $data)
    {
        $request = Request::create('/payments', 'POST', $data);
        $request->setUserResolver(function () { return User::find(1); });
        return $request;
    }

    private function createPayment(array $overrides = [])
    {
        return $this->controller()->store($this->request($overrides + [
            'booking_id' => 1, 'customer_id' => 1, 'amount' => '125.25',
            'payment_date' => '2026-09-25', 'payment_method' => 'cash',
            'cash_bank_account_id' => ChartOfAccount::where('code', '1101')->value('id'),
        ]));
    }

    public function test_payment_and_journal_post_together_with_dimensions_and_no_duplicate_journal()
    {
        $this->assertSame(201, $this->createPayment()->getStatusCode());
        $payment = Payment::first();
        $entry = $payment->journalEntry;
        $this->assertSame('posted', $entry->status);
        $this->assertSame('125.25', $entry->lines[0]->debit);
        $this->assertSame('125.25', $entry->lines[1]->credit);
        $this->assertSame('2300', $entry->lines[1]->account->code);
        foreach ($entry->lines as $line) {
            $this->assertEquals(1, $line->project_id);
            $this->assertEquals(1, $line->customer_id);
        }
        app(PaymentAccountingService::class)->post($payment, 1);
        $this->assertSame(1, JournalEntry::count());
        $this->assertSame('125.25', Booking::find(1)->paid_amount);
    }

    public function test_closed_period_rolls_back_receipt_and_booking_changes()
    {
        AccountingPeriod::query()->update(['status' => 'closed']);
        try { $this->createPayment(); $this->fail('A closed period accepted a payment.'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('entry_date', $e->errors()); }
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame('0.00', Booking::find(1)->paid_amount);
        $this->assertSame(0, DB::table('financial_audits')->count());
    }

    public function test_reversal_is_atomic_and_retains_original_entries()
    {
        $this->createPayment();
        $payment = Payment::first();
        AccountingPeriod::query()->update(['status' => 'closed']);
        $request = $this->request(['reason' => 'Receipt cancelled', 'reversal_date' => '2026-09-26']);
        try { $this->controller()->reverse($request, $payment); $this->fail('Closed-period reversal succeeded.'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('entry_date', $e->errors()); }
        $this->assertSame('verified', $payment->fresh()->status);
        $this->assertSame('125.25', Booking::find(1)->paid_amount);
        AccountingPeriod::query()->update(['status' => 'open']);
        $this->controller()->reverse($request, $payment);
        $this->assertSame('reversed', $payment->fresh()->status);
        $this->assertSame('0.00', Booking::find(1)->paid_amount);
        $this->assertSame(2, JournalEntry::count());
        $reversal = $payment->fresh()->reversalJournal;
        $this->assertSame('125.25', $reversal->lines[0]->credit);
        $this->assertSame('125.25', $reversal->lines[1]->debit);
        $this->assertEquals($payment->journalEntry->id, $reversal->reverses_entry_id);
        app(PaymentAccountingService::class)->reverse($payment, 1, '2026-09-26', 'Repeat');
        $this->assertSame(2, JournalEntry::count());
    }

    public function test_invalid_receiving_account_and_subcent_amount_are_rejected()
    {
        foreach ([['cash_bank_account_id' => ChartOfAccount::where('code', '2300')->value('id')], ['amount' => '10.001']] as $data) {
            try { $this->createPayment($data); $this->fail('Invalid payment accepted.'); }
            catch (ValidationException $e) { $this->assertSame(0, Payment::count()); }
        }
    }

    public function test_payment_journal_cannot_be_reversed_through_manual_journals()
    {
        $this->createPayment();
        $this->expectException(ValidationException::class);
        (new JournalEntryController)->reverse(Payment::first()->journalEntry,
            $this->request(['entry_date' => '2026-09-26', 'reason' => 'Bypass']), new AccountingPeriodService);
    }

    public function test_legacy_reversal_does_not_create_an_unmatched_journal()
    {
        $legacy = Payment::create(['receipt_number' => 'OLD-1', 'booking_id' => 1, 'customer_id' => 1, 'amount' => '20.00', 'payment_date' => '2026-09-01', 'payment_method' => 'cash', 'status' => 'verified', 'received_by' => 1]);
        Booking::find(1)->update(['paid_amount' => 20, 'remaining_amount' => 980]);
        $this->controller()->reverse($this->request(['reason' => 'Legacy correction']), $legacy);
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame('reversed', $legacy->fresh()->status);
    }
}
