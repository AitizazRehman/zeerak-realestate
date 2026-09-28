<?php

namespace Tests\Feature;

use App\Http\Controllers\API\AccountsReceivableController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class AccountsReceivableTest extends TestCase
{
    private $user;
    private $controller;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_number')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name');
            $table->string('code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->string('property_number')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('property_id');
            $table->string('booking_number');
            $table->string('status');
            $table->decimal('final_price', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('remaining_amount', 15, 2)->default(0);
            $table->date('booking_date');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('installment_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->string('plan_name');
            $table->string('frequency')->default('monthly');
            $table->decimal('total_amount', 15, 2);
            $table->decimal('down_payment', 15, 2)->default(0);
            $table->decimal('installment_amount', 15, 2);
            $table->unsignedInteger('number_of_installments');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('installments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('installment_plan_id');
            $table->unsignedBigInteger('booking_id');
            $table->unsignedInteger('installment_number');
            $table->date('due_date');
            $table->decimal('amount', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('remaining_amount', 15, 2)->default(0);
            $table->string('status')->default('pending');
            $table->date('paid_date')->nullable();
            $table->timestamps();
        });

        DB::table('users')->insert(['id' => 1, 'name' => 'Admin']);
        DB::table('customers')->insert([
            ['id' => 1, 'customer_number' => 'CUS-001', 'name' => 'Alpha Customer', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'customer_number' => 'CUS-002', 'name' => 'Beta Customer', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('projects')->insert([
            ['id' => 1, 'name' => 'Project One', 'code' => 'P1', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('properties')->insert([
            ['id' => 1, 'project_id' => 1, 'property_number' => 'A-01', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'project_id' => 1, 'property_number' => 'A-02', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('bookings')->insert([
            [
                'id' => 1,
                'customer_id' => 1,
                'property_id' => 1,
                'booking_number' => 'BKG-001',
                'status' => 'confirmed',
                'final_price' => 1500,
                'paid_amount' => 500,
                'remaining_amount' => 1000,
                'booking_date' => '2026-01-01',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'customer_id' => 2,
                'property_id' => 2,
                'booking_number' => 'BKG-002',
                'status' => 'confirmed',
                'final_price' => 500,
                'paid_amount' => 100,
                'remaining_amount' => 400,
                'booking_date' => '2026-01-01',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('installment_plans')->insert([
            [
                'id' => 1,
                'booking_id' => 1,
                'plan_name' => 'Plan A',
                'total_amount' => 600,
                'installment_amount' => 200,
                'number_of_installments' => 3,
                'start_date' => '2026-08-01',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'booking_id' => 2,
                'plan_name' => 'Plan B',
                'total_amount' => 400,
                'installment_amount' => 400,
                'number_of_installments' => 1,
                'start_date' => '2026-05-01',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('installments')->insert([
            [
                'id' => 1,
                'installment_plan_id' => 1,
                'booking_id' => 1,
                'installment_number' => 1,
                'due_date' => '2026-09-18',
                'amount' => 200,
                'remaining_amount' => 200,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'installment_plan_id' => 1,
                'booking_id' => 1,
                'installment_number' => 2,
                'due_date' => '2026-08-01',
                'amount' => 300,
                'remaining_amount' => 300,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 3,
                'installment_plan_id' => 1,
                'booking_id' => 1,
                'installment_number' => 3,
                'due_date' => '2026-10-10',
                'amount' => 100,
                'remaining_amount' => 100,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 4,
                'installment_plan_id' => 2,
                'booking_id' => 2,
                'installment_number' => 1,
                'due_date' => '2026-05-01',
                'amount' => 400,
                'remaining_amount' => 400,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->user = Mockery::mock(User::class)->makePartial();
        $this->user->id = 1;
        $this->user->branch_id = null;
        $this->user->shouldReceive('hasAnyRole')->andReturn(true);
        Auth::setUser($this->user);

        $this->controller = new AccountsReceivableController();
    }

    private function request(array $data = [])
    {
        $request = Request::create('/api/accounting/accounts-receivable/aging', 'GET', $data);
        $user = $this->user;
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $request;
    }

    public function test_aging_reconciles_scheduled_and_unallocated_balances()
    {
        $response = $this->controller->aging($this->request([
            'as_of' => '2026-09-28',
            'per_page' => 25,
        ]))->getData(true);

        $this->assertSame('1400.00', $response['summary']['total']);
        $this->assertSame('500.00', $response['summary']['current']);
        $this->assertSame('200.00', $response['summary']['1_30']);
        $this->assertSame('300.00', $response['summary']['31_60']);
        $this->assertSame('0.00', $response['summary']['61_90']);
        $this->assertSame('400.00', $response['summary']['90_plus']);
        $this->assertSame('900.00', $response['summary']['overdue']);
        $this->assertSame('400.00', $response['summary']['unallocated']);

        $this->assertSame(
            (float) $response['summary']['total'],
            (float) $response['summary']['current']
                + (float) $response['summary']['1_30']
                + (float) $response['summary']['31_60']
                + (float) $response['summary']['61_90']
                + (float) $response['summary']['90_plus']
        );
    }

    public function test_customer_rows_are_sorted_by_overdue_exposure()
    {
        $response = $this->controller->aging($this->request([
            'as_of' => '2026-09-28',
            'per_page' => 25,
        ]))->getData(true);

        $rows = $response['customers']['data'];

        $this->assertSame(1, $rows[0]['customer_id']);
        $this->assertSame('500.00', $rows[0]['overdue']);
        $this->assertSame(2, $rows[1]['customer_id']);
        $this->assertSame('400.00', $rows[1]['overdue']);
    }

    public function test_bucket_filter_limits_customer_list_without_changing_summary()
    {
        $response = $this->controller->aging($this->request([
            'as_of' => '2026-09-28',
            'bucket' => '90_plus',
            'per_page' => 25,
        ]))->getData(true);

        $this->assertSame('1400.00', $response['summary']['total']);
        $this->assertSame(1, $response['customers']['total']);
        $this->assertSame(2, $response['customers']['data'][0]['customer_id']);
    }
}
