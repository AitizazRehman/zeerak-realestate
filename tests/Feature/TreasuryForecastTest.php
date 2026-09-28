<?php

namespace Tests\Feature;

use App\Http\Controllers\API\TreasuryController;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\TreasuryCommitment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\AccountingTestCase;

class TreasuryForecastTest extends AccountingTestCase
{
    private $controller;
    private $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-28 12:00:00'));

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

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('chart_of_account_id');
            $table->string('account_type')->default('bank');
            $table->string('name');
            $table->string('bank_name')->nullable();
            $table->string('account_title')->nullable();
            $table->string('account_number')->nullable();
            $table->string('iban')->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('currency')->default('PKR');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('customer_number')->nullable();
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->string('property_number')->nullable();
        });

        Schema::table('installment_plans', function (Blueprint $table) {
            $table->string('plan_name')->nullable();
            $table->string('frequency')->default('monthly');
            $table->decimal('total_amount', 15, 2)->nullable();
            $table->decimal('down_payment', 15, 2)->default(0);
            $table->decimal('installment_amount', 15, 2)->nullable();
            $table->unsignedInteger('number_of_installments')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('installments', function (Blueprint $table) {
            $table->unsignedInteger('installment_number')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->string('status')->default('pending');
            $table->date('paid_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('vendor_number');
            $table->string('name');
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
            $table->unsignedBigInteger('cash_bank_account_id')->nullable();
            $table->decimal('amount', 18, 2);
            $table->date('payment_date');
            $table->dateTime('reversed_at')->nullable();
            $table->date('reversal_date')->nullable();
            $table->timestamps();
        });

        Schema::create('vendor_bill_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_bill_payment_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->decimal('amount', 18, 2);
            $table->timestamps();
        });

        $treasuryMigration = require database_path('migrations/2026_09_28_120000_create_treasury_commitments_table.php');
        $treasuryMigration->up();

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

        $bankCoa = ChartOfAccount::where('code', '1101')->firstOrFail();

        DB::table('bank_accounts')->insert([
            'id' => 1,
            'branch_id' => 1,
            'chart_of_account_id' => $bankCoa->id,
            'account_type' => 'bank',
            'name' => 'Main Bank',
            'bank_name' => 'Test Bank',
            'currency' => 'PKR',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $entry = JournalEntry::create([
            'entry_number' => 'JE-OPEN',
            'entry_date' => '2026-09-28',
            'description' => 'Opening treasury liquidity',
            'status' => 'posted',
            'created_by' => 1,
            'posted_by' => 1,
            'posted_at' => now(),
        ]);

        $entry->lines()->create([
            'chart_of_account_id' => $bankCoa->id,
            'debit' => 1000,
            'credit' => 0,
            'description' => 'Opening cash',
        ]);

        DB::table('customers')->where('id', 1)->update([
            'branch_id' => 1,
            'customer_number' => 'CUS-001',
            'name' => 'Test Customer',
        ]);

        DB::table('properties')->where('id', 1)->update([
            'project_id' => 1,
            'property_number' => 'A-01',
        ]);

        DB::table('bookings')->where('id', 1)->update([
            'customer_id' => 1,
            'property_id' => 1,
            'booking_number' => 'BKG-001',
            'status' => 'confirmed',
            'final_price' => 1200,
            'paid_amount' => 0,
            'remaining_amount' => 1200,
            'booking_date' => '2026-09-01',
        ]);

        DB::table('installment_plans')->insert([
            'id' => 1,
            'booking_id' => 1,
            'plan_name' => 'Active Plan',
            'total_amount' => 1000,
            'installment_amount' => 500,
            'number_of_installments' => 2,
            'start_date' => '2026-10-08',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('installments')->insert([
            [
                'id' => 1,
                'installment_plan_id' => 1,
                'booking_id' => 1,
                'installment_number' => 1,
                'due_date' => '2026-10-08',
                'amount' => 500,
                'paid_amount' => 0,
                'remaining_amount' => 500,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'installment_plan_id' => 1,
                'booking_id' => 1,
                'installment_number' => 2,
                'due_date' => '2027-01-15',
                'amount' => 500,
                'paid_amount' => 0,
                'remaining_amount' => 500,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('vendors')->insert([
            'id' => 1,
            'branch_id' => 1,
            'vendor_number' => 'VND-001',
            'name' => 'Test Vendor',
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
            'bill_date' => '2026-09-20',
            'due_date' => '2026-10-03',
            'description' => 'Vendor obligation',
            'total_amount' => 300,
            'paid_amount' => 0,
            'remaining_amount' => 300,
            'status' => 'posted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('vendor_bill_lines')->insert([
            'vendor_bill_id' => 1,
            'chart_of_account_id' => ChartOfAccount::where('code', '6100')->value('id') ?: $bankCoa->id,
            'project_id' => 1,
            'description' => 'Vendor obligation',
            'amount' => 300,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        TreasuryCommitment::create([
            'branch_id' => 1,
            'project_id' => 1,
            'flow_type' => 'outflow',
            'category' => 'Tax / Government',
            'title' => 'Planned tax payment',
            'expected_date' => '2026-10-05',
            'amount' => 200,
            'probability_percent' => 50,
            'status' => 'planned',
            'created_by' => 1,
            'updated_by' => 1,
        ]);

        $this->user = Mockery::mock(User::class)->makePartial();
        $this->user->id = 1;
        $this->user->branch_id = 1;
        $this->user->shouldReceive('hasAnyRole')->andReturn(true);
        Auth::setUser($this->user);

        $this->controller = new TreasuryController();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function request(array $data)
    {
        $request = Request::create('/api/accounting/treasury/forecast', 'GET', $data);
        $user = $this->user;

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $request;
    }

    public function test_forecast_combines_liquidity_receivables_payables_and_weighted_commitments()
    {
        $response = $this->controller->forecast($this->request([
            'branch_id' => 1,
            'horizon_days' => 90,
            'interval' => 'weekly',
            'collection_percent' => 80,
            'payable_percent' => 100,
            'use_commitment_probability' => 1,
        ]))->getData(true);

        $this->assertSame('1000.00', $response['summary']['opening_liquidity']);
        $this->assertSame('400.00', $response['summary']['forecast_inflows']);
        $this->assertSame('400.00', $response['summary']['forecast_outflows']);
        $this->assertSame('0.00', $response['summary']['net_change']);
        $this->assertSame('1000.00', $response['summary']['projected_closing_liquidity']);
        $this->assertSame('600.00', $response['summary']['lowest_projected_balance']);
        $this->assertSame('0.00', $response['summary']['funding_gap']);
        $this->assertSame('200.00', $response['summary']['unscheduled_receivables']);

        $this->assertSame(3, count($response['flows']));

        $sources = collect($response['flows'])->pluck('source')->sort()->values()->all();
        $this->assertSame(
            ['customer_installment','treasury_commitment','vendor_bill'],
            $sources
        );
    }

    public function test_forecast_detects_intra_period_funding_gap()
    {
        TreasuryCommitment::create([
            'branch_id' => 1,
            'project_id' => 1,
            'flow_type' => 'outflow',
            'category' => 'Construction',
            'title' => 'Large contractor advance',
            'expected_date' => '2026-09-29',
            'amount' => 1500,
            'probability_percent' => 100,
            'status' => 'planned',
            'created_by' => 1,
            'updated_by' => 1,
        ]);

        $response = $this->controller->forecast($this->request([
            'branch_id' => 1,
            'horizon_days' => 90,
            'interval' => 'weekly',
            'collection_percent' => 80,
            'payable_percent' => 100,
            'use_commitment_probability' => 1,
        ]))->getData(true);

        $this->assertSame('-500.00', $response['summary']['projected_closing_liquidity']);
        $this->assertSame('-900.00', $response['summary']['lowest_projected_balance']);
        $this->assertSame('900.00', $response['summary']['funding_gap']);
        $this->assertNotNull($response['summary']['lowest_period']);
    }
}
