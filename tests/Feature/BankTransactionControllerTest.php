<?php

namespace Tests\Feature;

use App\Http\Controllers\API\BankTransactionController;
use App\Models\BankTransaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BankTransactionControllerTest extends TestCase
{
    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('chart_of_account_id');
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bank_account_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->date('transaction_date');
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->string('source')->default('manual');
            $table->string('reconciliation_status')->default('unmatched');
            $table->softDeletes();
            $table->timestamps();
        });

        DB::table('bank_accounts')->insert([
            'id' => 1,
            'branch_id' => null,
            'chart_of_account_id' => 1101,
            'is_active' => true,
        ]);

        $this->user = \Mockery::mock(User::class)->makePartial();
        $this->user->id = 1;
        $this->user->branch_id = null;
        $this->user->shouldReceive('hasAnyRole')->andReturn(true);

        Auth::setUser($this->user);
    }

    private function request(array $data)
    {
        $request = Request::create('/api/accounting/bank-transactions', 'POST', $data);
        $user = $this->user;
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $request;
    }

    public function test_transaction_rejects_debit_and_credit_together()
    {
        $this->expectException(ValidationException::class);

        (new BankTransactionController)->store($this->request([
            'bank_account_id' => 1,
            'transaction_date' => '2026-09-27',
            'debit' => 100,
            'credit' => 100,
        ]));
    }

    public function test_transaction_rejects_zero_debit_and_credit()
    {
        $this->expectException(ValidationException::class);

        (new BankTransactionController)->store($this->request([
            'bank_account_id' => 1,
            'transaction_date' => '2026-09-27',
            'debit' => 0,
            'credit' => 0,
        ]));
    }

    public function test_reconciled_transaction_cannot_be_edited()
    {
        DB::table('bank_transactions')->insert([
            'id' => 1,
            'bank_account_id' => 1,
            'branch_id' => null,
            'transaction_date' => '2026-09-27',
            'debit' => 100,
            'credit' => 0,
            'source' => 'manual',
            'reconciliation_status' => 'reconciled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            (new BankTransactionController)->update(
                $this->request([
                    'bank_account_id' => 1,
                    'transaction_date' => '2026-09-27',
                    'debit' => 200,
                    'credit' => 0,
                ]),
                BankTransaction::findOrFail(1)
            );

            $this->fail('Expected reconciled transaction update to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertStringContainsString('cannot be edited', $exception->getMessage());
        }
    }
}
