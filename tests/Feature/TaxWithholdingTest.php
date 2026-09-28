<?php

namespace Tests\Feature;

use App\Http\Controllers\API\TaxManagementController;
use App\Models\ChartOfAccount;
use App\Models\TaxCode;
use App\Models\TaxTransaction;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\VendorPayableAccountingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AccountingTestCase;

class TaxWithholdingTest extends AccountingTestCase
{
    private $service;
    private $cashId;
    private $payableId;
    private $expenseId;
    private $withholdingId;
    private $vendor;
    private $bill;
    private $taxCode;

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

        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable();
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
            'branch_id' => 1,
            'name' => 'Project One',
            'code' => 'P1',
            'is_active' => 1,
        ]);

        DB::table('customers')->where('id', 1)->update([
            'branch_id' => 1,
        ]);

        $apMigration = require database_path('migrations/2026_09_28_100000_create_vendor_payables_tables.php');
        $apMigration->up();

        $allocationMigration = require database_path('migrations/2026_09_28_110000_create_vendor_bill_payment_allocations_table.php');
        $allocationMigration->up();

        $taxMigration = require database_path('migrations/2026_09_28_160000_create_tax_management_tables.php');
        $taxMigration->up();

        $this->cashId = ChartOfAccount::where('code', '1101')->value('id');
        $this->payableId = $this->ensureAccount('2100', 'Accounts Payable', 'liability', 'credit', false, true);
        $this->withholdingId = $this->ensureAccount('2410', 'Withholding Tax Payable', 'liability', 'credit', false, true);
        $this->expenseId = $this->ensureAccount('6400', 'Office Expense', 'expense', 'debit', true, false);

        $this->vendor = Vendor::create([
            'branch_id' => 1,
            'vendor_number' => 'V-001',
            'name' => 'Test Vendor',
            'tax_number' => 'NTN-001',
            'payment_terms_days' => 30,
            'is_active' => true,
        ]);

        $this->bill = VendorBill::create([
            'vendor_id' => $this->vendor->id,
            'branch_id' => 1,
            'project_id' => 1,
            'bill_number' => 'BILL-TAX-001',
            'vendor_invoice_number' => 'INV-TAX-001',
            'bill_date' => '2026-09-01',
            'due_date' => '2026-09-30',
            'description' => 'Tax withholding test bill',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'remaining_amount' => 1000,
            'status' => 'posted',
            'created_by' => 1,
        ]);

        $this->bill->lines()->create([
            'chart_of_account_id' => $this->expenseId,
            'project_id' => 1,
            'description' => 'Professional service',
            'amount' => 1000,
        ]);

        $this->taxCode = TaxCode::create([
            'code' => 'WHT-10',
            'name' => 'Vendor Withholding 10%',
            'tax_type' => 'withholding_payable',
            'rate_percent' => 10,
            'chart_of_account_id' => $this->withholdingId,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'certificate_required' => true,
            'is_active' => true,
        ]);

        $this->service = new VendorPayableAccountingService();
        $this->service->postBill($this->bill, 1);
    }

    private function ensureAccount($code, $name, $type, $normal, $manual, $control)
    {
        $account = ChartOfAccount::where('code', $code)->first();

        if ($account) {
            $account->update([
                'name' => $name,
                'account_type' => $type,
                'normal_balance' => $normal,
                'allow_manual_posting' => $manual,
                'is_control_account' => $control,
                'is_system' => $control,
                'is_active' => true,
            ]);
            return $account->id;
        }

        return ChartOfAccount::create([
            'code' => $code,
            'name' => $name,
            'account_type' => $type,
            'normal_balance' => $normal,
            'allow_manual_posting' => $manual,
            'is_control_account' => $control,
            'is_system' => $control,
            'is_active' => true,
        ])->id;
    }

    private function paymentData($key = 'tax-payment-001')
    {
        return [
            'amount' => 1000,
            'cash_bank_account_id' => $this->cashId,
            'payment_date' => '2026-09-20',
            'payment_method' => 'bank_transfer',
            'reference_number' => 'BANK-001',
            'request_key' => $key,
            'withholdings' => [
                [
                    'tax_code_id' => $this->taxCode->id,
                    'taxable_amount' => 1000,
                ],
            ],
        ];
    }

    private function controller()
    {
        return new class extends TaxManagementController {
            protected function canAccessAllBranches()
            {
                return true;
            }
        };
    }

    private function request($method, $uri, array $data = [])
    {
        $request = Request::create($uri, $method, $data);
        $user = Auth::user();

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $request;
    }

    public function test_vendor_payment_posts_gross_ap_net_cash_and_withholding_liability()
    {
        $payment = $this->service->payBill($this->bill, $this->paymentData(), 1);
        $entry = $payment->journalEntry()->firstOrFail();

        $this->assertSame('1000.00', $payment->amount);

        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $this->payableId,
            'debit' => 1000.00,
            'credit' => 0.00,
            'project_id' => 1,
        ]);

        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $this->cashId,
            'debit' => 0.00,
            'credit' => 900.00,
            'project_id' => 1,
        ]);

        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $this->withholdingId,
            'debit' => 0.00,
            'credit' => 100.00,
            'project_id' => 1,
        ]);

        $transaction = TaxTransaction::firstOrFail();

        $this->assertSame('withholding_payable', $transaction->tax_type);
        $this->assertSame('1000.00', $transaction->taxable_amount);
        $this->assertSame('10.0000', $transaction->tax_rate_percent);
        $this->assertSame('100.00', $transaction->tax_amount);
        $this->assertSame('900.00', $transaction->net_amount);
        $this->assertSame('pending', $transaction->certificate_status);

        $this->assertDatabaseHas('tax_transaction_allocations', [
            'tax_transaction_id' => $transaction->id,
            'project_id' => 1,
            'taxable_amount' => 1000.00,
            'tax_amount' => 100.00,
            'net_amount' => 900.00,
        ]);

        $this->bill->refresh();
        $this->assertSame('paid', $this->bill->status);
        $this->assertSame('0.00', $this->bill->remaining_amount);
    }

    public function test_payment_retry_is_idempotent_with_same_withholding()
    {
        $first = $this->service->payBill($this->bill, $this->paymentData(), 1);
        $second = $this->service->payBill($this->bill->fresh(), $this->paymentData(), 1);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DB::table('vendor_bill_payments')->count());
        $this->assertSame(1, DB::table('tax_transactions')->count());
        $this->assertSame(1, DB::table('tax_transaction_allocations')->count());
    }

    public function test_payment_reversal_marks_tax_transaction_reversed_and_reverses_tax_journal()
    {
        $payment = $this->service->payBill($this->bill, $this->paymentData(), 1);

        $reversal = $this->service->reversePayment(
            $payment,
            '2026-09-21',
            'Vendor payment correction',
            1
        );

        $transaction = TaxTransaction::firstOrFail();
        $this->assertSame('reversed', $transaction->status);
        $this->assertSame('voided', $transaction->certificate_status);
        $this->assertNotNull($transaction->reversed_at);

        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $reversal->id,
            'chart_of_account_id' => $this->withholdingId,
            'debit' => 100.00,
            'credit' => 0.00,
            'project_id' => 1,
        ]);

        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $reversal->id,
            'chart_of_account_id' => $this->cashId,
            'debit' => 900.00,
            'credit' => 0.00,
            'project_id' => 1,
        ]);

        $this->bill->refresh();
        $this->assertSame('posted', $this->bill->status);
        $this->assertSame('1000.00', $this->bill->remaining_amount);
    }

    public function test_withholding_certificate_and_register_are_generated_from_tax_transaction()
    {
        $payment = $this->service->payBill($this->bill, $this->paymentData(), 1);
        $transaction = $payment->taxTransactions()->firstOrFail();

        $issued = $this->controller()->issueCertificate(
            $this->request('POST', '/tax/'.$transaction->id.'/certificate'),
            $transaction
        )->getData(true);

        $this->assertSame('issued', $issued['data']['certificate_status']);
        $this->assertStringStartsWith('WHT-202609-', $issued['data']['certificate_number']);

        $register = $this->controller()->register(
            $this->request('GET', '/tax/register', [
                'from' => '2026-09-01',
                'to' => '2026-09-30',
            ])
        )->getData(true);

        $this->assertSame('1000.00', $register['summary']['taxable_amount']);
        $this->assertSame('100.00', $register['summary']['tax_amount']);
        $this->assertSame(1, $register['summary']['issued_certificates']);
        $this->assertCount(1, $register['ledger_positions']);
        $this->assertSame('100.00', $register['ledger_positions'][0]['register_amount']);
        $this->assertSame('100.00', $register['ledger_positions'][0]['ledger_balance']);
        $this->assertSame('0.00', $register['ledger_positions'][0]['difference']);
    }
}
