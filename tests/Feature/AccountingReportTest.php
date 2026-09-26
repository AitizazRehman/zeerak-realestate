<?php

namespace Tests\Feature;

use App\Http\Controllers\API\AccountingReportController;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountingReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::statement('CREATE TABLE chart_of_accounts (id INTEGER PRIMARY KEY, code TEXT, name TEXT)');
        DB::statement('CREATE TABLE journal_entries (id INTEGER PRIMARY KEY, entry_number TEXT, entry_date TEXT, description TEXT, status TEXT)');
        DB::statement('CREATE TABLE journal_lines (id INTEGER PRIMARY KEY, journal_entry_id INTEGER, chart_of_account_id INTEGER, debit DECIMAL(18,2), credit DECIMAL(18,2), description TEXT, project_id INTEGER, customer_id INTEGER)');
        DB::statement('CREATE TABLE projects (id INTEGER PRIMARY KEY, name TEXT)');
        DB::statement('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT, customer_number TEXT)');
        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'code' => '1101', 'name' => 'Bank'],
            ['id' => 2, 'code' => '3100', 'name' => 'Capital'],
        ]);
        DB::table('projects')->insert(['id' => 1, 'name' => 'Project One']);
        DB::table('customers')->insert(['id' => 1, 'name' => 'Customer One', 'customer_number' => 'C-1']);
    }

    private function journal($id, $date, $amount, $status = 'posted', $reverse = false, $project = null, $customer = null)
    {
        DB::table('journal_entries')->insert(['id' => $id, 'entry_number' => 'JE-'.$id, 'entry_date' => $date, 'description' => 'Test journal', 'status' => $status]);
        foreach ([1, 2] as $account) {
            $debit = ($account === 1) !== $reverse;
            DB::table('journal_lines')->insert([
                'journal_entry_id' => $id, 'chart_of_account_id' => $account,
                'debit' => $debit ? $amount : '0.00', 'credit' => $debit ? '0.00' : $amount,
                'project_id' => $project, 'customer_id' => $customer,
            ]);
        }
    }

    private function report($method, array $filters = [])
    {
        $filters += ['from' => '2026-09-01', 'to' => '2026-09-30'];
        Paginator::currentPageResolver(function () use ($filters) { return $filters['page'] ?? 1; });
        $request = Request::create('/report', 'GET', $filters);
        return (new AccountingReportController)->$method($request)->getData(true);
    }

    public function test_ledger_includes_opening_and_reversal_but_excludes_drafts_and_future_entries()
    {
        $this->journal(1, '2026-08-31', '100.00');
        $this->journal(2, '2026-09-01', '20.25');
        $this->journal(3, '2026-09-30', '5.10', 'posted', true);
        $this->journal(4, '2026-09-15', '900.00', 'draft');
        $this->journal(5, '2026-10-01', '700.00');
        $result = $this->report('ledger', ['account_id' => 1]);
        $this->assertSame(['opening' => '100.00', 'debit' => '20.25', 'credit' => '5.10', 'closing' => '115.15'], $result['summary']);
        $this->assertSame(2, $result['entries']['total']);
        $this->assertSame(['120.25', '115.15'], array_column($result['entries']['data'], 'balance'));
        $trial = $this->report('trialBalance');
        $this->assertSame(['debit' => '115.15', 'credit' => '115.15', 'difference' => '0.00', 'balanced' => true], $trial['summary']);
    }

    public function test_running_balance_continues_on_the_second_page_with_same_day_entries()
    {
        $this->journal(1, '2026-08-31', '10.00');
        for ($id = 2; $id <= 52; $id++) $this->journal($id, '2026-09-01', '1.00');
        $result = $this->report('ledger', ['account_id' => 1, 'page' => 2]);
        $this->assertSame(51, $result['entries']['total']);
        $this->assertCount(1, $result['entries']['data']);
        $this->assertSame('61.00', $result['entries']['data'][0]['balance']);
        $this->assertSame('61.00', $result['summary']['closing']);
    }

    public function test_dimensions_apply_to_opening_and_period_movements()
    {
        $this->journal(1, '2026-08-31', '10.00', 'posted', false, 1, 1);
        $this->journal(2, '2026-09-01', '5.00', 'posted', false, 1, 1);
        $this->journal(3, '2026-09-02', '100.00');
        $this->journal(4, '2026-08-01', '200.00');
        $result = $this->report('ledger', ['account_id' => 1, 'project_id' => 1, 'customer_id' => 1]);
        $this->assertSame('10.00', $result['summary']['opening']);
        $this->assertSame('15.00', $result['summary']['closing']);
        $trial = $this->report('trialBalance', ['project_id' => 1, 'customer_id' => 1]);
        $this->assertSame('15.00', $trial['summary']['debit']);
        $this->assertTrue($trial['summary']['balanced']);
    }

    public function test_opening_only_and_empty_reports()
    {
        $this->assertSame([], $this->report('trialBalance')['data']);
        $this->journal(1, '2026-08-31', '3.00', 'posted', true);
        $result = $this->report('ledger', ['account_id' => 1]);
        $this->assertSame('-3.00', $result['summary']['closing']);
        $this->assertSame([], $result['entries']['data']);
        $this->assertSame('3.00', $this->report('trialBalance')['data'][0]['closing_credit']);
    }

    public function test_reversed_date_range_is_rejected()
    {
        $this->expectException(ValidationException::class);
        $this->report('trialBalance', ['from' => '2026-10-01', 'to' => '2026-09-01']);
    }
}
