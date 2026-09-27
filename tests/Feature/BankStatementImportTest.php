<?php

namespace Tests\Feature;

use App\Http\Controllers\API\BankStatementImportController;
use App\Models\BankStatementImport;
use App\Models\User;
use App\Services\BankStatementFileReader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class BankStatementImportTest extends TestCase
{
    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'filesystems.default' => 'local',
        ]);
        DB::purge('sqlite');
        Storage::fake('local');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('chart_of_account_id');
            $table->string('account_type')->default('bank');
            $table->string('name');
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('currency')->default('PKR');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('bank_statement_imports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bank_account_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('file_type');
            $table->string('file_hash');
            $table->unsignedInteger('header_row')->default(1);
            $table->text('column_mapping')->nullable();
            $table->string('status')->default('uploaded');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->decimal('statement_opening_balance', 18, 2)->nullable();
            $table->decimal('statement_closing_balance', 18, 2)->nullable();
            $table->text('notes')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->dateTime('imported_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bank_account_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->date('transaction_date');
            $table->date('value_date')->nullable();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->decimal('running_balance', 18, 2)->nullable();
            $table->string('reference_number')->nullable();
            $table->string('cheque_number')->nullable();
            $table->string('external_transaction_id')->nullable();
            $table->string('transaction_hash', 64)->nullable();
            $table->text('description')->nullable();
            $table->string('source')->default('manual');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('bank_statement_import_id')->nullable();
            $table->string('reconciliation_status')->default('unmatched');
            $table->string('match_method')->nullable();
            $table->dateTime('reconciled_at')->nullable();
            $table->unsignedBigInteger('reconciled_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('users')->insert(['id' => 1, 'name' => 'Admin']);
        DB::table('bank_accounts')->insert([
            'id' => 1,
            'branch_id' => null,
            'chart_of_account_id' => 1101,
            'account_type' => 'bank',
            'name' => 'Main Bank',
            'bank_name' => 'Test Bank',
            'account_number' => '1234',
            'currency' => 'PKR',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user = Mockery::mock(User::class)->makePartial();
        $this->user->id = 1;
        $this->user->branch_id = null;
        $this->user->shouldReceive('hasAnyRole')->andReturn(true);
        Auth::setUser($this->user);
    }

    private function createImport($id, $path)
    {
        DB::table('bank_statement_imports')->insert([
            'id' => $id,
            'bank_account_id' => 1,
            'branch_id' => null,
            'original_filename' => 'statement.csv',
            'stored_path' => $path,
            'file_type' => 'csv',
            'file_hash' => str_repeat((string) $id, 64),
            'header_row' => 1,
            'status' => 'uploaded',
            'uploaded_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return BankStatementImport::findOrFail($id);
    }

    private function request()
    {
        $request = Request::create('/api/accounting/bank-statement-imports/1/commit', 'POST', [
            'mapping' => [
                'transaction_date' => 0,
                'amount' => 1,
                'reference_number' => 2,
            ],
            'amount_mode' => 'positive_deposit',
        ]);

        $user = $this->user;
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $request;
    }

    public function test_signed_amount_import_maps_cash_flow_and_second_batch_is_duplicate()
    {
        $path = 'bank-statements/test.csv';
        Storage::put($path, "Date,Amount,Reference\n2026-09-01,100.00,DEP-1\n2026-09-02,-25.00,WD-1\n");

        $controller = new BankStatementImportController();
        $reader = new BankStatementFileReader();

        $first = $controller->commit($this->request(), $this->createImport(1, $path), $reader)->getData(true);

        $this->assertSame(2, $first['summary']['imported_rows']);
        $this->assertSame(0, $first['summary']['duplicate_rows']);
        $this->assertDatabaseHas('bank_transactions', [
            'reference_number' => 'DEP-1',
            'debit' => 100.00,
            'credit' => 0.00,
        ]);
        $this->assertDatabaseHas('bank_transactions', [
            'reference_number' => 'WD-1',
            'debit' => 0.00,
            'credit' => 25.00,
        ]);

        $second = $controller->commit($this->request(), $this->createImport(2, $path), $reader)->getData(true);

        $this->assertSame(0, $second['summary']['imported_rows']);
        $this->assertSame(2, $second['summary']['duplicate_rows']);
        $this->assertSame(2, DB::table('bank_transactions')->count());
    }
}
