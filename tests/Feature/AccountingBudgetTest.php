<?php

namespace Tests\Feature;

use App\Http\Controllers\API\AccountingBudgetController;
use App\Models\AccountingBudget;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AccountingTestCase;

class AccountingBudgetTest extends AccountingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

        $migration = require database_path('migrations/2026_09_28_130000_create_accounting_budgets_tables.php');
        $migration->up();

        foreach ([
            ['4100','Sales Revenue','revenue','credit'],
            ['6500','Operating Expense','expense','debit'],
        ] as $account) {
            if (!ChartOfAccount::where('code', $account[0])->exists()) {
                ChartOfAccount::create([
                    'code' => $account[0],
                    'name' => $account[1],
                    'account_type' => $account[2],
                    'normal_balance' => $account[3],
                    'allow_manual_posting' => true,
                    'is_active' => true,
                ]);
            }
        }

        $this->journal('2026-09-10', [
            ['1101', 900, 0],
            ['4100', 0, 900],
        ]);

        $this->journal('2026-09-12', [
            ['6500', 400, 0],
            ['1101', 0, 400],
        ]);
    }

    private function controller()
    {
        return new class extends AccountingBudgetController {
            protected function canAccessAllBranches()
            {
                return true;
            }
        };
    }

    private function request($method, $uri, array $data = [])
    {
        $request = Request::create($uri, $method, $data);
        $user = Auth::user();

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $request;
    }

    private function journal($date, array $lines)
    {
        $entry = JournalEntry::create([
            'entry_number' => 'JE-BUD-'.str_pad((string) (JournalEntry::count() + 1), 3, '0', STR_PAD_LEFT),
            'entry_date' => $date,
            'description' => 'Budget actual test',
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
                'project_id' => 1,
                'customer_id' => 1,
                'description' => 'Budget actual test',
            ]);
        }

        return $entry;
    }

    private function createBudget()
    {
        $year = DB::table('fiscal_years')->where('name', '2026')->first();
        $period = DB::table('accounting_periods')->where('fiscal_year_id', $year->id)->first();

        $response = $this->controller()->store($this->request('POST', '/budget', [
            'fiscal_year_id' => $year->id,
            'branch_id' => 1,
            'project_id' => 1,
            'name' => 'FY2026 Project Budget',
            'notes' => 'Test budget',
            'lines' => [
                [
                    'accounting_period_id' => $period->id,
                    'chart_of_account_id' => ChartOfAccount::where('code', '4100')->value('id'),
                    'amount' => 1000,
                ],
                [
                    'accounting_period_id' => $period->id,
                    'chart_of_account_id' => ChartOfAccount::where('code', '6500')->value('id'),
                    'amount' => 500,
                ],
            ],
        ]))->getData(true);

        return AccountingBudget::findOrFail($response['budget']['id']);
    }

    public function test_budget_creation_variance_and_approval_locking()
    {
        $budget = $this->createBudget();

        $this->assertSame('draft', $budget->status);
        $this->assertSame(2, $budget->lines()->count());
        $this->assertSame('1500.00', number_format((float) $budget->lines()->sum('amount'), 2, '.', ''));

        $variance = $this->controller()->variance(
            $this->request('GET', '/budget/'.$budget->id.'/variance'),
            $budget
        )->getData(true);

        $this->assertSame('1000.00', $variance['summary']['budget_revenue']);
        $this->assertSame('900.00', $variance['summary']['actual_revenue']);
        $this->assertSame('500.00', $variance['summary']['budget_costs']);
        $this->assertSame('400.00', $variance['summary']['actual_costs']);
        $this->assertSame('500.00', $variance['summary']['budget_result']);
        $this->assertSame('500.00', $variance['summary']['actual_result']);
        $this->assertSame('0.00', $variance['summary']['result_variance']);

        $rows = collect($variance['accounts'])->keyBy('code');

        $this->assertSame('-100.00', $rows['4100']['variance']);
        $this->assertFalse($rows['4100']['favorable']);
        $this->assertSame('100.00', $rows['6500']['variance']);
        $this->assertTrue($rows['6500']['favorable']);

        $approve = $this->controller()->approve(
            $this->request('POST', '/budget/'.$budget->id.'/approve'),
            $budget
        )->getData(true);

        $this->assertSame('approved', $approve['budget']['status']);

        try {
            $this->controller()->update(
                $this->request('PUT', '/budget/'.$budget->id, [
                    'fiscal_year_id' => $budget->fiscal_year_id,
                    'branch_id' => 1,
                    'project_id' => 1,
                    'name' => 'Changed Budget',
                    'lines' => [
                        [
                            'accounting_period_id' => $budget->lines()->first()->accounting_period_id,
                            'chart_of_account_id' => ChartOfAccount::where('code', '4100')->value('id'),
                            'amount' => 1000,
                        ],
                    ],
                ]),
                $budget
            );

            $this->fail('Approved budget was editable.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_variance_can_be_limited_through_a_selected_period()
    {
        $budget = $this->createBudget();
        $periodId = $budget->lines()->first()->accounting_period_id;

        $variance = $this->controller()->variance(
            $this->request('GET', '/budget/'.$budget->id.'/variance', [
                'through_period_id' => $periodId,
            ]),
            $budget
        )->getData(true);

        $this->assertSame($periodId, $variance['through_period_id']);
        $this->assertCount(1, $variance['periods']);
    }
}
