<?php

namespace Tests\Feature;

use App\Http\Controllers\API\{ExpenseController, SalesDashboardController, SalesReportController};
use App\Models\{AccountingPeriod, ChartOfAccount, Expense, JournalEntry, User};
use App\Services\ExpenseAccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AccountingTestCase;

class ExpenseAccountingTest extends AccountingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('CREATE TABLE branches (id INTEGER PRIMARY KEY, name TEXT, deleted_at TEXT)');
        DB::table('branches')->insert(['id' => 1, 'name' => 'Head Office']);
        require_once database_path('migrations/2026_09_09_120000_create_expenses_table.php');
        (new \CreateExpensesTable)->up();
        DB::statement('ALTER TABLE expenses ADD COLUMN branch_id INTEGER');
        require_once database_path('migrations/2026_09_27_000000_add_expense_accounting_fields.php');
        (new \AddExpenseAccountingFields)->up();
        DB::statement('CREATE TABLE financial_documents (id INTEGER PRIMARY KEY, entity_type TEXT, entity_id INTEGER)');
        ChartOfAccount::create(['code' => '6300', 'name' => 'Utilities', 'account_type' => 'expense', 'normal_balance' => 'debit']);
    }

    private function controller()
    {
        return new class extends ExpenseController {
            protected function canAccessAllBranches() { return true; }
        };
    }

    private function request(array $data)
    {
        $request = Request::create('/expenses', 'POST', $data);
        $request->setUserResolver(function () { return User::find(1); });
        return $request;
    }

    private function data(array $overrides = [])
    {
        return $overrides + [
            'project_id' => 1, 'category' => 'Utilities', 'description' => 'Electricity bill',
            'amount' => '125.25', 'expense_date' => '2026-09-25', 'payment_method' => 'cash',
            'expense_account_id' => ChartOfAccount::where('code', '6300')->value('id'),
            'cash_bank_account_id' => ChartOfAccount::where('code', '1101')->value('id'),
        ];
    }

    private function createExpense()
    {
        $this->controller()->store($this->request($this->data()));
        return Expense::first();
    }

    public function test_expense_creates_balanced_project_journal_once()
    {
        $expense = $this->createExpense();
        $entry = $expense->journalEntry;
        $this->assertSame('posted', $entry->status);
        $this->assertSame('6300', $entry->lines[0]->account->code);
        $this->assertSame('1101', $entry->lines[1]->account->code);
        $this->assertSame('125.25', $entry->lines[0]->debit);
        $this->assertSame('125.25', $entry->lines[1]->credit);
        $this->assertEquals(1, $entry->lines[0]->project_id);
        $this->assertEquals(1, $entry->lines[1]->project_id);
        app(ExpenseAccountingService::class)->post($expense, 1);
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_closed_period_rolls_back_expense_journal_and_audit()
    {
        AccountingPeriod::query()->update(['status' => 'closed']);
        try { $this->createExpense(); $this->fail('Expense posted in a closed period.'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('entry_date', $e->errors()); }
        $this->assertSame(0, Expense::count());
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, DB::table('financial_audits')->count());
    }

    public function test_posted_financial_details_and_deletion_are_locked_but_notes_can_change()
    {
        $expense = $this->createExpense();
        try { $this->controller()->update($this->request($this->data(['amount' => '200.00'])), $expense); $this->fail('Posted amount changed.'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        try { $this->controller()->destroy($expense); $this->fail('Posted expense deleted.'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        $this->controller()->update($this->request($this->data(['notes' => 'Receipt attached'])), $expense);
        $this->assertSame('Receipt attached', $expense->fresh()->notes);
        $this->assertSame('125.25', $expense->fresh()->amount);
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_reversal_preserves_record_documents_and_journals_and_is_atomic()
    {
        $expense = $this->createExpense();
        DB::table('financial_documents')->insert(['entity_type' => 'expense', 'entity_id' => $expense->id]);
        $request = $this->request(['reversal_date' => '2026-09-26', 'reason' => 'Duplicate bill']);
        AccountingPeriod::query()->update(['status' => 'closed']);
        try { $this->controller()->reverse($request, $expense); $this->fail('Closed-period reversal accepted.'); }
        catch (ValidationException $e) { $this->assertNull($expense->fresh()->reversed_at); }
        AccountingPeriod::query()->update(['status' => 'open']);
        $this->controller()->reverse($request, $expense);
        $this->assertNotNull($expense->fresh()->reversed_at);
        $this->assertNull($expense->fresh()->deleted_at);
        $this->assertSame(1, DB::table('financial_documents')->count());
        $this->assertSame(2, JournalEntry::count());
        $reversal = $expense->fresh()->reversalJournal;
        $this->assertSame('125.25', $reversal->lines[0]->credit);
        $this->assertSame('125.25', $reversal->lines[1]->debit);
        $this->assertEquals($expense->journalEntry->id, $reversal->reverses_entry_id);
        $this->assertSame(0, Expense::unreversed()->count());
        try { $this->controller()->reverse($request, $expense); $this->fail('Duplicate reversal accepted.'); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        $this->assertSame(2, JournalEntry::count());
    }

    public function test_operational_report_queries_exclude_reversed_expenses()
    {
        $expense = $this->createExpense();
        $this->controller()->reverse($this->request(['reversal_date' => '2026-09-26', 'reason' => 'Cancelled']), $expense);
        foreach ([SalesDashboardController::class, SalesReportController::class] as $class) {
            $controller = \Mockery::mock($class)->makePartial()->shouldAllowMockingProtectedMethods();
            $controller->shouldReceive('canAccessAllBranches')->andReturn(true);
            $method = new \ReflectionMethod($class, 'branchExpenses');
            $method->setAccessible(true);
            $this->assertEquals(0, $method->invoke($controller, Expense::query())->sum('amount'));
        }
    }

    public function test_invalid_accounts_and_amounts_do_not_create_expenses()
    {
        foreach ([['expense_account_id' => ChartOfAccount::where('code', '1101')->value('id')], ['cash_bank_account_id' => ChartOfAccount::where('code', '2300')->value('id')], ['amount' => '0.001']] as $data) {
            try { $this->controller()->store($this->request($this->data($data))); $this->fail('Invalid expense accepted.'); }
            catch (ValidationException $e) { $this->assertSame(0, Expense::count()); }
        }
    }

    public function test_legacy_reversal_has_no_unmatched_journal()
    {
        $data = $this->data();
        unset($data['expense_account_id'], $data['cash_bank_account_id']);
        $expense = Expense::create($data + ['expense_number' => 'OLD-EXP', 'created_by' => 1]);
        $this->controller()->reverse($this->request(['reversal_date' => '2026-09-26', 'reason' => 'Legacy correction']), $expense);
        $this->assertSame(0, JournalEntry::count());
        $this->assertNotNull($expense->fresh()->reversed_at);
    }
}
