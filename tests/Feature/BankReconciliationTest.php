<?php

namespace Tests\Feature;

use App\Http\Controllers\API\BankReconciliationController;
use App\Models\BankStatementImport;
use App\Models\BankTransaction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class BankReconciliationTest extends TestCase
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
            $table->string('status')->default('imported');
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

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_number');
            $table->date('entry_date');
            $table->string('description')->nullable();
            $table->string('status');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedBigInteger('chart_of_account_id');
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->string('description')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
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
            $table->string('source')->default('statement_import');
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

        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bank_account_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('bank_statement_import_id')->unique();
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('statement_opening_balance', 18, 2)->nullable();
            $table->decimal('statement_closing_balance', 18, 2);
            $table->decimal('gl_balance', 18, 2)->default(0);
            $table->decimal('outstanding_deposits', 18, 2)->default(0);
            $table->decimal('outstanding_payments', 18, 2)->default(0);
            $table->decimal('unmatched_bank_deposits', 18, 2)->default(0);
            $table->decimal('unmatched_bank_withdrawals', 18, 2)->default(0);
            $table->decimal('adjusted_bank_balance', 18, 2)->default(0);
            $table->decimal('difference', 18, 2)->default(0);
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->dateTime('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('bank_reconciliation_matches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bank_transaction_id')->unique();
            $table->unsignedBigInteger('journal_line_id')->unique();
            $table->unsignedBigInteger('bank_reconciliation_id')->nullable();
            $table->string('match_method');
            $table->unsignedInteger('confidence_score')->nullable();
            $table->unsignedBigInteger('matched_by')->nullable();
            $table->dateTime('matched_at');
            $table->timestamps();
        });

        Schema::create('bank_reconciliation_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bank_transaction_id');
            $table->unsignedBigInteger('bank_reconciliation_id')->nullable();
            $table->timestamps();
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

        $this->controller = new BankReconciliationController();
    }

    private function request($method = 'POST', array $data = [])
    {
        $request = Request::create('/reconciliation', $method, $data);
        $user = $this->user;
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $request;
    }

    private function statementImport($id = 1, $closing = '100.00')
    {
        DB::table('bank_statement_imports')->insert([
            'id' => $id,
            'bank_account_id' => 1,
            'branch_id' => null,
            'original_filename' => 'statement-'.$id.'.csv',
            'stored_path' => 'bank-statements/statement-'.$id.'.csv',
            'file_type' => 'csv',
            'file_hash' => str_pad((string) $id, 64, (string) $id),
            'header_row' => 1,
            'status' => 'imported',
            'statement_closing_balance' => $closing,
            'uploaded_by' => 1,
            'imported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return BankStatementImport::findOrFail($id);
    }

    private function bankTransaction($id, $importId, $date, $debit, $credit, $reference = null)
    {
        DB::table('bank_transactions')->insert([
            'id' => $id,
            'bank_account_id' => 1,
            'branch_id' => null,
            'transaction_date' => $date,
            'debit' => $debit,
            'credit' => $credit,
            'reference_number' => $reference,
            'source' => 'statement_import',
            'bank_statement_import_id' => $importId,
            'reconciliation_status' => 'unmatched',
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return BankTransaction::findOrFail($id);
    }

    private function journal($entryId, $lineId, $date, $debit, $credit, $number = null, $description = null)
    {
        DB::table('journal_entries')->insert([
            'id' => $entryId,
            'entry_number' => $number ?: 'JE-'.$entryId,
            'entry_date' => $date,
            'description' => $description ?: 'Test bank movement',
            'status' => 'posted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('journal_lines')->insert([
            'id' => $lineId,
            'journal_entry_id' => $entryId,
            'chart_of_account_id' => 1101,
            'debit' => $debit,
            'credit' => $credit,
            'description' => $description,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_auto_match_leaves_equal_candidates_for_manual_review()
    {
        $import = $this->statementImport();
        $this->bankTransaction(1, $import->id, '2026-09-10', 100, 0, 'DEP-100');
        $this->journal(1, 1, '2026-09-10', 100, 0, 'JE-1');
        $this->journal(2, 2, '2026-09-10', 100, 0, 'JE-2');

        $response = $this->controller->autoMatch($this->request(), $import)->getData(true);

        $this->assertSame(0, $response['summary']['matched']);
        $this->assertSame(1, $response['summary']['ambiguous']);
        $this->assertSame(0, DB::table('bank_reconciliation_matches')->count());
    }

    public function test_manual_match_prevents_same_gl_line_from_being_used_twice()
    {
        $import = $this->statementImport();
        $first = $this->bankTransaction(1, $import->id, '2026-09-10', 100, 0);
        $second = $this->bankTransaction(2, $import->id, '2026-09-10', 100, 0);
        $this->journal(1, 1, '2026-09-10', 100, 0);

        $this->controller->match($this->request('POST', ['journal_line_id' => 1]), $first);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->controller->match($this->request('POST', ['journal_line_id' => 1]), $second);
    }

    public function test_finalize_accepts_outstanding_book_payment_when_adjusted_bank_equals_gl()
    {
        $import = $this->statementImport(1, '100.00');
        $transaction = $this->bankTransaction(1, $import->id, '2026-09-30', 100, 0, 'DEP-1');

        $this->journal(1, 1, '2026-09-30', 100, 0, 'JE-DEP', 'Deposit');
        $this->journal(2, 2, '2026-09-29', 0, 20, 'JE-PAY', 'Outstanding payment');

        $this->controller->match(
            $this->request('POST', ['journal_line_id' => 1]),
            $transaction
        );

        $response = $this->controller->finalize($this->request('POST', [
            'bank_statement_import_id' => 1,
            'statement_closing_balance' => 100,
        ]))->getData(true);

        $this->assertSame('completed', $response['reconciliation']['status']);
        $this->assertSame('80.00', $response['reconciliation']['gl_balance']);
        $this->assertSame('20.00', $response['reconciliation']['outstanding_payments']);
        $this->assertSame('80.00', $response['reconciliation']['adjusted_bank_balance']);
        $this->assertSame('0.00', $response['reconciliation']['difference']);

        $this->assertDatabaseHas('bank_transactions', [
            'id' => 1,
            'reconciliation_status' => 'reconciled',
        ]);
    }
}
