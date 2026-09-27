<?php

namespace Tests\Feature;

use App\Http\Controllers\API\BankReconciliationAdjustmentController;
use App\Models\BankReconciliationAdjustment;
use App\Models\BankTransaction;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountingPeriodService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\AccountingTestCase;

class BankReconciliationAdjustmentTest extends AccountingTestCase
{
    private $user;
    private $controller;

    protected function setUp(): void
    {
        parent::setUp();

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
            $table->unsignedBigInteger('bank_statement_import_id');
            $table->string('status')->default('draft');
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
            $table->unsignedBigInteger('offset_account_id');
            $table->unsignedBigInteger('journal_entry_id')->unique();
            $table->unsignedBigInteger('reversal_journal_entry_id')->nullable()->unique();
            $table->string('adjustment_type');
            $table->decimal('amount', 18, 2);
            $table->string('direction');
            $table->string('description');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->dateTime('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
        });

        $bankLedger = ChartOfAccount::where('code', '1101')->firstOrFail();
        $expense = ChartOfAccount::create([
            'code' => '6100',
            'name' => 'Bank Charges',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'allow_manual_posting' => true,
            'is_active' => true,
        ]);

        DB::table('bank_accounts')->insert([
            'id' => 1,
            'branch_id' => null,
            'chart_of_account_id' => $bankLedger->id,
            'account_type' => 'bank',
            'name' => 'Main Bank',
            'bank_name' => 'Test Bank',
            'account_number' => '1234',
            'currency' => 'PKR',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bank_statement_imports')->insert([
            'id' => 1,
            'bank_account_id' => 1,
            'branch_id' => null,
            'original_filename' => 'statement.csv',
            'stored_path' => 'bank-statements/statement.csv',
            'file_type' => 'csv',
            'file_hash' => str_repeat('a', 64),
            'header_row' => 1,
            'status' => 'imported',
            'uploaded_by' => 1,
            'imported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bank_transactions')->insert([
            'id' => 1,
            'bank_account_id' => 1,
            'branch_id' => null,
            'transaction_date' => '2026-09-20',
            'debit' => 0,
            'credit' => 1500,
            'description' => 'Monthly bank charges',
            'source' => 'statement_import',
            'bank_statement_import_id' => 1,
            'reconciliation_status' => 'unmatched',
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->user = Mockery::mock(User::class)->makePartial();
        $this->user->id = 1;
        $this->user->branch_id = null;
        $this->user->shouldReceive('hasAnyRole')->andReturn(true);
        Auth::setUser($this->user);

        $this->controller = new BankReconciliationAdjustmentController();
        $this->expenseAccountId = $expense->id;
        $this->bankLedgerId = $bankLedger->id;
    }

    private function request(array $data)
    {
        $request = Request::create('/adjustment', 'POST', $data);
        $user = $this->user;
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $request;
    }

    public function test_withdrawal_adjustment_posts_expense_debit_bank_credit_and_matches()
    {
        $response = $this->controller->store(
            $this->request([
                'adjustment_type' => 'bank_charge',
                'offset_account_id' => $this->expenseAccountId,
                'description' => 'Bank charges September',
            ]),
            BankTransaction::findOrFail(1),
            new AccountingPeriodService()
        )->getData(true);

        $adjustment = BankReconciliationAdjustment::findOrFail($response['adjustment']['id']);
        $entry = JournalEntry::with('lines')->findOrFail($adjustment->journal_entry_id);

        $this->assertSame('posted', $entry->status);
        $this->assertSame('bank_reconciliation_adjustment', $entry->source_type);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $this->expenseAccountId,
            'debit' => 1500.00,
            'credit' => 0.00,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $this->bankLedgerId,
            'debit' => 0.00,
            'credit' => 1500.00,
        ]);
        $this->assertDatabaseHas('bank_transactions', [
            'id' => 1,
            'journal_entry_id' => $entry->id,
            'reconciliation_status' => 'matched',
        ]);
        $this->assertSame(1, DB::table('bank_reconciliation_matches')->count());
    }

    public function test_reversal_posts_opposite_entry_and_returns_statement_row_to_unmatched()
    {
        $this->controller->store(
            $this->request([
                'adjustment_type' => 'bank_charge',
                'offset_account_id' => $this->expenseAccountId,
                'description' => 'Bank charges September',
            ]),
            BankTransaction::findOrFail(1),
            new AccountingPeriodService()
        );

        $adjustment = BankReconciliationAdjustment::firstOrFail();

        $response = $this->controller->reverse(
            $this->request([
                'entry_date' => '2026-09-21',
                'reason' => 'Wrong expense account selected',
            ]),
            $adjustment,
            new AccountingPeriodService()
        )->getData(true);

        $reversal = JournalEntry::with('lines')->findOrFail($response['reversal']['id']);

        $this->assertSame($adjustment->journal_entry_id, $reversal->reverses_entry_id);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $reversal->id,
            'chart_of_account_id' => $this->expenseAccountId,
            'debit' => 0.00,
            'credit' => 1500.00,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $reversal->id,
            'chart_of_account_id' => $this->bankLedgerId,
            'debit' => 1500.00,
            'credit' => 0.00,
        ]);
        $this->assertDatabaseHas('bank_transactions', [
            'id' => 1,
            'journal_entry_id' => null,
            'reconciliation_status' => 'unmatched',
        ]);
        $this->assertSame(0, DB::table('bank_reconciliation_matches')->count());

        $fresh = BankReconciliationAdjustment::findOrFail($adjustment->id);
        $this->assertSame($reversal->id, $fresh->reversal_journal_entry_id);
        $this->assertNotNull($fresh->reversed_at);
    }
}
