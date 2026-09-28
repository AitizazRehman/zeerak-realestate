<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Services\VendorPayableAccountingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccountingTestCase;

class AccountsPayableTest extends AccountingTestCase
{
    private $service;
    private $expenseAccountId;
    private $cashAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('vendor_number');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('vendor_bills', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('bill_number');
            $table->string('vendor_invoice_number')->nullable();
            $table->date('bill_date');
            $table->date('due_date');
            $table->string('description');
            $table->decimal('total_amount', 18, 2);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('remaining_amount', 18, 2);
            $table->string('status');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->date('cancellation_date')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('vendor_bill_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_bill_id');
            $table->unsignedBigInteger('chart_of_account_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('description');
            $table->decimal('amount', 18, 2);
            $table->timestamps();
        });

        Schema::create('vendor_bill_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_bill_id');
            $table->unsignedBigInteger('cash_bank_account_id');
            $table->string('request_key')->unique();
            $table->decimal('amount', 18, 2);
            $table->date('payment_date');
            $table->string('payment_method');
            $table->string('reference_number')->nullable();
            $table->string('cheque_number')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->dateTime('reversed_at')->nullable();
            $table->date('reversal_date')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('vendor_bill_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_bill_payment_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->decimal('amount', 18, 2);
            $table->timestamps();
        });

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

        DB::table('vendors')->insert([
            'id' => 1,
            'branch_id' => 1,
            'vendor_number' => 'VND-001',
            'name' => 'Test Vendor',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('vendor_bills')->insert([
            'id' => 1,
            'vendor_id' => 1,
            'branch_id' => 1,
            'project_id' => 1,
            'bill_number' => 'BILL-001',
            'vendor_invoice_number' => 'INV-001',
            'bill_date' => '2026-09-10',
            'due_date' => '2026-09-30',
            'description' => 'Office supplies',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'remaining_amount' => 1000,
            'status' => 'posted',
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('vendor_bill_lines')->insert([
            'vendor_bill_id' => 1,
            'chart_of_account_id' => $expense->id,
            'project_id' => 1,
            'description' => 'Office supplies',
            'amount' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service = new VendorPayableAccountingService();
        $this->expenseAccountId = $expense->id;
        $this->cashAccountId = $cash->id;
    }

    public function test_vendor_bill_accrual_posts_expense_and_accounts_payable()
    {
        $bill = VendorBill::findOrFail(1);
        $entry = $this->service->postBill($bill, 1);

        $this->assertSame('vendor_bill', $entry->source_type);
        $this->assertSame('posted', $entry->status);

        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $this->expenseAccountId,
            'debit' => 1000.00,
            'credit' => 0.00,
            'project_id' => 1,
        ]);

        $payable = ChartOfAccount::where('code', '2100')->firstOrFail();

        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $payable->id,
            'debit' => 0.00,
            'credit' => 1000.00,
        ]);
    }

    public function test_partial_and_final_payments_settle_bill_and_reversal_reopens_balance()
    {
        $bill = VendorBill::findOrFail(1);
        $this->service->postBill($bill, 1);

        $first = $this->service->payBill($bill, [
            'amount' => 400,
            'cash_bank_account_id' => $this->cashAccountId,
            'payment_date' => '2026-09-20',
            'payment_method' => 'bank_transfer',
            'reference_number' => 'PAY-1',
            'request_key' => 'ap-payment-1',
        ], 1);

        $bill->refresh();
        $this->assertSame('partial', $bill->status);
        $this->assertSame('400.00', $bill->paid_amount);
        $this->assertSame('600.00', $bill->remaining_amount);

        $payable = ChartOfAccount::where('code', '2100')->firstOrFail();
        $firstJournal = $first->journalEntry()->firstOrFail();

        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $firstJournal->id,
            'chart_of_account_id' => $payable->id,
            'debit' => 400.00,
            'credit' => 0.00,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $firstJournal->id,
            'chart_of_account_id' => $this->cashAccountId,
            'debit' => 0.00,
            'credit' => 400.00,
        ]);

        $second = $this->service->payBill($bill, [
            'amount' => 600,
            'cash_bank_account_id' => $this->cashAccountId,
            'payment_date' => '2026-09-25',
            'payment_method' => 'bank_transfer',
            'reference_number' => 'PAY-2',
            'request_key' => 'ap-payment-2',
        ], 1);

        $bill->refresh();
        $this->assertSame('paid', $bill->status);
        $this->assertSame('1000.00', $bill->paid_amount);
        $this->assertSame('0.00', $bill->remaining_amount);

        $this->service->reversePayment($second, '2026-09-26', 'Payment returned by bank', 1);

        $bill->refresh();
        $this->assertSame('partial', $bill->status);
        $this->assertSame('400.00', $bill->paid_amount);
        $this->assertSame('600.00', $bill->remaining_amount);

        $second->refresh();
        $this->assertNotNull($second->reversed_at);
        $this->assertNotNull($second->reversalJournal()->first());
    }

    public function test_bill_cannot_be_cancelled_until_active_payments_are_reversed()
    {
        $bill = VendorBill::findOrFail(1);
        $this->service->postBill($bill, 1);

        $payment = $this->service->payBill($bill, [
            'amount' => 250,
            'cash_bank_account_id' => $this->cashAccountId,
            'payment_date' => '2026-09-20',
            'payment_method' => 'cash',
            'request_key' => 'ap-payment-cancel-test',
        ], 1);

        try {
            $this->service->cancelBill($bill, '2026-09-21', 'Invoice entered in error', 1);
            $this->fail('Expected cancellation with active payment to fail.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'Reverse all active vendor payments',
                $e->validator->errors()->first('accounting')
            );
        }

        $this->service->reversePayment($payment, '2026-09-22', 'Cash payment voided', 1);
        $this->service->cancelBill($bill, '2026-09-23', 'Invoice entered in error', 1);

        $bill->refresh();
        $this->assertSame('cancelled', $bill->status);
        $this->assertSame('0.00', $bill->remaining_amount);
        $this->assertNotNull($bill->cancellationJournal()->first());
    }
    public function test_multi_project_bill_and_payment_keep_project_ap_balanced()
    {
        DB::table('projects')->insert([
            'id' => 2,
            'branch_id' => 1,
        ]);

        DB::table('vendor_bills')->where('id', 1)->update([
            'project_id' => null,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'remaining_amount' => 1000,
            'status' => 'posted',
        ]);

        DB::table('vendor_bill_lines')->where('vendor_bill_id', 1)->delete();

        DB::table('vendor_bill_lines')->insert([
            [
                'vendor_bill_id' => 1,
                'chart_of_account_id' => $this->expenseAccountId,
                'project_id' => 1,
                'description' => 'Project one share',
                'amount' => 600,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'vendor_bill_id' => 1,
                'chart_of_account_id' => $this->expenseAccountId,
                'project_id' => 2,
                'description' => 'Project two share',
                'amount' => 400,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $bill = VendorBill::findOrFail(1);
        $entry = $this->service->postBill($bill, 1);
        $payable = ChartOfAccount::where('code', '2100')->firstOrFail();

        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $payable->id,
            'project_id' => 1,
            'credit' => 600.00,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $payable->id,
            'project_id' => 2,
            'credit' => 400.00,
        ]);

        $payment = $this->service->payBill($bill, [
            'amount' => 500,
            'cash_bank_account_id' => $this->cashAccountId,
            'payment_date' => '2026-09-20',
            'payment_method' => 'bank_transfer',
            'request_key' => 'ap-project-allocation',
        ], 1);

        $this->assertDatabaseHas('vendor_bill_payment_allocations', [
            'vendor_bill_payment_id' => $payment->id,
            'project_id' => 1,
            'amount' => 300.00,
        ]);
        $this->assertDatabaseHas('vendor_bill_payment_allocations', [
            'vendor_bill_payment_id' => $payment->id,
            'project_id' => 2,
            'amount' => 200.00,
        ]);

        $paymentEntry = $payment->journalEntry()->firstOrFail();

        $projectOneBalance = DB::table('journal_lines')
            ->where('chart_of_account_id', $payable->id)
            ->where('project_id', 1)
            ->sum(DB::raw('credit - debit'));

        $projectTwoBalance = DB::table('journal_lines')
            ->where('chart_of_account_id', $payable->id)
            ->where('project_id', 2)
            ->sum(DB::raw('credit - debit'));

        $this->assertSame(300.0, (float) $projectOneBalance);
        $this->assertSame(200.0, (float) $projectTwoBalance);
        $this->assertNotNull($paymentEntry);
    }

}
