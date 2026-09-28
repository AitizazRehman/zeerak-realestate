<?php

namespace Tests\Feature;

use App\Http\Controllers\API\ExecutiveFinanceController;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AccountingTestCase;

class ExecutiveFinanceTest extends AccountingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::table('projects', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->boolean('is_active')->default(true);
        });

        DB::table('projects')->where('id', 1)->update([
            'name' => 'Project One',
            'code' => 'P1',
            'is_active' => 1,
        ]);

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('chart_of_account_id');
            $table->string('account_type')->default('bank');
            $table->string('name');
            $table->string('bank_name')->nullable();
            $table->string('currency')->default('PKR');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        foreach ([
            ['1200','Accounts Receivable','asset','debit',true],
            ['2100','Accounts Payable','liability','credit',true],
            ['3000','Owner Equity','equity','credit',false],
            ['4100','Sales Revenue','revenue','credit',false],
            ['5100','Cost of Sales','cost_of_sales','debit',false],
            ['6500','Operating Expense','expense','debit',false],
        ] as $account) {
            if (!ChartOfAccount::where('code', $account[0])->exists()) {
                ChartOfAccount::create([
                    'code' => $account[0],
                    'name' => $account[1],
                    'account_type' => $account[2],
                    'normal_balance' => $account[3],
                    'is_control_account' => $account[4],
                    'is_system' => $account[4],
                    'is_active' => true,
                ]);
            }
        }

        $cashId = ChartOfAccount::where('code', '1101')->value('id');

        DB::table('bank_accounts')->insert([
            'id' => 1,
            'branch_id' => null,
            'chart_of_account_id' => $cashId,
            'account_type' => 'bank',
            'name' => 'Main Cash',
            'bank_name' => 'Test Bank',
            'currency' => 'PKR',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->journal('2026-08-31', [
            ['1101', 1000, 0, null, null],
            ['3000', 0, 1000, null, null],
        ], 'Opening capital');

        $this->journal('2026-09-01', [
            ['1200', 1000, 0, 1, 1],
            ['4100', 0, 1000, 1, 1],
        ], 'Recognized sale');

        $this->journal('2026-09-01', [
            ['5100', 300, 0, 1, 1],
            ['1101', 0, 300, 1, 1],
        ], 'Cost of sale');

        $this->journal('2026-09-02', [
            ['6500', 100, 0, 1, 1],
            ['1101', 0, 100, 1, 1],
        ], 'Operating cost');

        $this->journal('2026-09-03', [
            ['6500', 200, 0, 1, null],
            ['2100', 0, 200, 1, null],
        ], 'Vendor accrual');

        $this->journal('2026-09-04', [
            ['1101', 200, 0, 1, 1],
            ['2300', 0, 200, 1, 1],
        ], 'Customer advance');
    }

    private function journal($date, array $lines, $description)
    {
        $entry = JournalEntry::create([
            'entry_number' => 'JE-EXEC-'.str_pad((string) (JournalEntry::count() + 1), 3, '0', STR_PAD_LEFT),
            'entry_date' => $date,
            'description' => $description,
            'status' => 'posted',
            'created_by' => 1,
            'posted_by' => 1,
            'posted_at' => $date.' 12:00:00',
        ]);

        foreach ($lines as $line) {
            $entry->lines()->create([
                'chart_of_account_id' => ChartOfAccount::where('code', $line[0])->value('id'),
                'debit' => $line[1],
                'credit' => $line[2],
                'project_id' => $line[3],
                'customer_id' => $line[4],
                'description' => $description,
            ]);
        }

        return $entry;
    }

    private function dashboard(array $filters = [])
    {
        $request = Request::create('/api/accounting/executive-finance/dashboard', 'GET', array_merge([
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ], $filters));

        return (new ExecutiveFinanceController())->dashboard($request)->getData(true);
    }

    public function test_company_dashboard_reconciles_profit_balance_sheet_and_control_accounts()
    {
        $result = $this->dashboard();

        $this->assertSame('1000.00', $result['profit_loss']['revenue']);
        $this->assertSame('300.00', $result['profit_loss']['cost_of_sales']);
        $this->assertSame('700.00', $result['profit_loss']['gross_profit']);
        $this->assertSame('300.00', $result['profit_loss']['operating_expenses']);
        $this->assertSame('400.00', $result['profit_loss']['net_result']);

        $this->assertSame('1800.00', $result['balance_sheet']['assets']);
        $this->assertSame('400.00', $result['balance_sheet']['liabilities']);
        $this->assertSame('1000.00', $result['balance_sheet']['equity_before_unclosed_earnings']);
        $this->assertSame('400.00', $result['balance_sheet']['unclosed_earnings']);
        $this->assertSame('1800.00', $result['balance_sheet']['liabilities_and_equity']);
        $this->assertSame('0.00', $result['balance_sheet']['difference']);
        $this->assertTrue($result['balance_sheet']['balanced']);

        $this->assertSame('800.00', $result['control_balances']['cash_bank']);
        $this->assertSame('1000.00', $result['control_balances']['accounts_receivable']);
        $this->assertSame('200.00', $result['control_balances']['accounts_payable']);
        $this->assertSame('200.00', $result['control_balances']['customer_advances']);
        $this->assertSame('1400.00', $result['control_balances']['operating_liquidity_position']);

        $this->assertSame(40.0, $result['kpis']['net_margin_percent']);
        $this->assertSame(900.0, $result['kpis']['cash_plus_ar_to_ap_percent']);
        $this->assertTrue($result['kpis']['trial_balance_balanced']);
        $this->assertTrue($result['kpis']['balance_sheet_balanced']);

        $this->assertCount(1, $result['project_performance']);
        $this->assertSame('1000.00', $result['project_performance'][0]['revenue']);
        $this->assertSame('600.00', $result['project_performance'][0]['recorded_costs']);
        $this->assertSame('400.00', $result['project_performance'][0]['recorded_result']);
        $this->assertSame(40.0, $result['project_performance'][0]['recorded_margin_percent']);
    }

    public function test_project_filter_keeps_project_statement_balanced_and_limits_project_results()
    {
        $result = $this->dashboard(['project_id' => 1]);

        $this->assertSame('1000.00', $result['profit_loss']['revenue']);
        $this->assertSame('400.00', $result['profit_loss']['net_result']);
        $this->assertSame('800.00', $result['balance_sheet']['assets']);
        $this->assertSame('800.00', $result['balance_sheet']['liabilities_and_equity']);
        $this->assertTrue($result['balance_sheet']['balanced']);
        $this->assertSame('-200.00', $result['control_balances']['cash_bank']);
        $this->assertCount(1, $result['project_performance']);
        $this->assertSame(1, $result['project_performance'][0]['project_id']);
    }
}
