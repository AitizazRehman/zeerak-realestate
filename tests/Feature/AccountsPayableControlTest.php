<?php

namespace Tests\Feature;

use App\Http\Controllers\API\AccountsPayableReportController;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\VendorPayableAccountingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\AccountingTestCase;

class AccountsPayableControlTest extends AccountingTestCase
{
    private $service;
    private $controller;
    private $cashAccountId;
    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->boolean('is_active')->default(true);
        });

        DB::table('branches')->insert([
            'id' => 1,
            'name' => 'Head Office',
            'code' => 'HO',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('projects')->where('id', 1)->update([
            'name' => 'Project One',
            'code' => 'P1',
            'is_active' => 1,
        ]);

        $vendorMigration = require database_path('migrations/2026_09_28_100000_create_vendor_payables_tables.php');
        $vendorMigration->up();

        $allocationMigration = require database_path('migrations/2026_09_28_110000_create_vendor_bill_payment_allocations_table.php');
        $allocationMigration->up();

        ChartOfAccount::create([
            'code' => '2100',
            'name' => 'Accounts Payable',
            'account_type' => 'liability',
            'normal_balance' => 'credit',
            'is_control_account' => true,
            'is_system' => true,
            'is_active' => true,
        ]);

        $expense = ChartOfAccount::create([
            'code' => '6400',
            'name' => 'Office Expense',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'allow_manual_posting' => true,
            'is_active' => true,
        ]);

        $cash = ChartOfAccount::where('code', '1101')->firstOrFail();
        $this->cashAccountId = $cash->id;

        $vendor = Vendor::create([
            'branch_id' => 1,
            'vendor_number' => 'VND-001',
            'name' => 'Control Test Vendor',
            'payment_terms_days' => 30,
            'is_active' => true,
        ]);

        $bill = VendorBill::create([
            'vendor_id' => $vendor->id,
            'branch_id' => 1,
            'project_id' => 1,
            'bill_number' => 'BILL-CTRL-001',
            'vendor_invoice_number' => 'INV-CTRL-001',
            'bill_date' => '2026-09-01',
            'due_date' => '2026-09-20',
            'description' => 'Control test bill',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'remaining_amount' => 1000,
            'status' => 'posted',
            'created_by' => 1,
        ]);

        $bill->lines()->create([
            'chart_of_account_id' => $expense->id,
            'project_id' => 1,
            'description' => 'Control test expense',
            'amount' => 1000,
        ]);

        $this->service = new VendorPayableAccountingService();
        $this->service->postBill($bill, 1);

        $this->service->payBill($bill, [
            'amount' => 300,
            'cash_bank_account_id' => $this->cashAccountId,
            'payment_date' => '2026-09-15',
            'payment_method' => 'bank_transfer',
            'reference_number' => 'BANK-300',
            'request_key' => 'control-payment-300',
        ], 1);

        $this->user = Mockery::mock(User::class)->makePartial();
        $this->user->id = 1;
        $this->user->branch_id = 1;
        $this->user->shouldReceive('hasAnyRole')->andReturn(true);
        Auth::setUser($this->user);

        $this->controller = new AccountsPayableReportController();
    }

    private function request(array $data)
    {
        $request = Request::create('/api/accounting/accounts-payable-control', 'GET', $data);
        $user = $this->user;

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $request;
    }

    public function test_reconciliation_forecast_and_statement_agree_after_partial_payment()
    {
        $reconciliation = $this->controller->reconciliation($this->request([
            'as_of' => '2026-09-26',
        ]))->getData(true);

        $this->assertSame('700.00', $reconciliation['subledger_balance']);
        $this->assertSame('700.00', $reconciliation['managed_gl_balance']);
        $this->assertSame('0.00', $reconciliation['managed_difference']);
        $this->assertTrue($reconciliation['managed_balanced']);
        $this->assertSame('700.00', $reconciliation['total_gl_balance']);
        $this->assertSame('0.00', $reconciliation['other_gl_activity']);
        $this->assertTrue($reconciliation['overall_balanced']);

        $forecast = $this->controller->forecast($this->request([
            'as_of' => '2026-09-26',
        ]))->getData(true);

        $this->assertSame('700.00', $forecast['summary']['total']);
        $this->assertSame('700.00', $forecast['summary']['overdue']);
        $this->assertSame(1, $forecast['summary']['bill_count']);

        $statement = $this->controller->statement($this->request([
            'vendor_id' => 1,
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ]))->getData(true);

        $this->assertSame('0.00', $statement['summary']['opening']);
        $this->assertSame('300.00', $statement['summary']['debit']);
        $this->assertSame('1000.00', $statement['summary']['credit']);
        $this->assertSame('700.00', $statement['summary']['closing']);
        $this->assertCount(2, $statement['entries']);
    }

    public function test_payment_reversal_restores_historical_vendor_and_gl_balance()
    {
        $payment = VendorBill::findOrFail(1)->payments()->firstOrFail();

        $this->service->reversePayment(
            $payment,
            '2026-09-26',
            'Bank rejected transfer',
            1
        );

        $reconciliation = $this->controller->reconciliation($this->request([
            'as_of' => '2026-09-27',
        ]))->getData(true);

        $this->assertSame('1000.00', $reconciliation['subledger_balance']);
        $this->assertSame('1000.00', $reconciliation['managed_gl_balance']);
        $this->assertSame('0.00', $reconciliation['managed_difference']);

        $statement = $this->controller->statement($this->request([
            'vendor_id' => 1,
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ]))->getData(true);

        $this->assertSame('300.00', $statement['summary']['debit']);
        $this->assertSame('1300.00', $statement['summary']['credit']);
        $this->assertSame('1000.00', $statement['summary']['closing']);
        $this->assertCount(3, $statement['entries']);
    }
}
