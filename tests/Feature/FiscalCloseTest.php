<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\FiscalYear;
use App\Models\FiscalYearClosure;
use App\Models\JournalEntry;
use App\Services\FiscalCloseService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingTestCase;

class FiscalCloseTest extends AccountingTestCase
{
    private $service;
    private $cashId;
    private $revenueId;
    private $expenseId;
    private $retainedId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashId = ChartOfAccount::where('code', '1101')->value('id');

        $this->retainedId = $this->ensureAccount(
            '3200',
            'Retained Earnings',
            'equity',
            'credit',
            false,
            true
        );

        $this->revenueId = $this->ensureAccount(
            '4100',
            'Property Sales',
            'revenue',
            'credit',
            true,
            false
        );

        $this->expenseId = $this->ensureAccount(
            '6500',
            'Operating Expense',
            'expense',
            'debit',
            true,
            false
        );

        $this->service = new FiscalCloseService();

        $year = FiscalYear::firstOrFail();
        $year->periods()->delete();

        for ($month = 1; $month <= 12; $month++) {
            $start = Carbon::create(2026, $month, 1)->startOfMonth();
            $year->periods()->create([
                'name' => $start->format('F Y'),
                'starts_on' => $start->toDateString(),
                'ends_on' => $start->copy()->endOfMonth()->toDateString(),
                'status' => $month === 12 ? 'open' : 'closed',
            ]);
        }

        $this->postedJournal('2026-09-10', [
            [$this->cashId, 1000, 0],
            [$this->revenueId, 0, 1000],
        ], 'Revenue');

        $this->postedJournal('2026-09-15', [
            [$this->expenseId, 400, 0],
            [$this->cashId, 0, 400],
        ], 'Operating expense');
    }

    private function ensureAccount($code, $name, $type, $normal, $manual, $control)
    {
        $account = ChartOfAccount::where('code', $code)->first();

        if ($account) {
            $account->update([
                'name' => $name,
                'account_type' => $type,
                'normal_balance' => $normal,
                'allow_manual_posting' => $manual,
                'is_control_account' => $control,
                'is_system' => $control,
                'is_active' => true,
            ]);

            return $account->id;
        }

        return ChartOfAccount::create([
            'code' => $code,
            'name' => $name,
            'account_type' => $type,
            'normal_balance' => $normal,
            'allow_manual_posting' => $manual,
            'is_control_account' => $control,
            'is_system' => $control,
            'is_active' => true,
        ])->id;
    }

    private function postedJournal($date, array $lines, $description)
    {
        $entry = JournalEntry::create([
            'entry_number' => 'JE-CLOSE-'.str_pad((string) (JournalEntry::count() + 1), 3, '0', STR_PAD_LEFT),
            'entry_date' => $date,
            'accounting_period_id' => AccountingPeriod::whereDate('starts_on', '<=', $date)
                ->whereDate('ends_on', '>=', $date)
                ->firstOrFail()->id,
            'description' => $description,
            'status' => 'posted',
            'created_by' => 1,
            'posted_by' => 1,
            'posted_at' => $date.' 12:00:00',
        ]);

        foreach ($lines as $line) {
            $entry->lines()->create([
                'chart_of_account_id' => $line[0],
                'debit' => $line[1],
                'credit' => $line[2],
                'description' => $description,
            ]);
        }

        return $entry;
    }

    public function test_year_end_close_zeros_profit_and_loss_into_retained_earnings_and_locks_year()
    {
        $migration = require database_path('migrations/2026_09_28_150000_create_fiscal_year_closures_table.php');
        $migration->up();

        $year = FiscalYear::firstOrFail();
        $readiness = $this->service->readiness($year);

        $this->assertTrue($readiness['ready']);
        $this->assertSame('600.00', $readiness['summary']['net_result']);
        $this->assertSame('0.00', $readiness['summary']['trial_balance_difference']);

        $closure = $this->service->close($year, 1);

        $this->assertSame('closed', $closure->status);
        $this->assertSame('600.00', $closure->net_result);
        $this->assertNotNull($closure->closing_journal_entry_id);

        $year->refresh();
        $this->assertSame('closed', $year->status);
        $this->assertSame('closed', AccountingPeriod::whereDate('ends_on', '2026-12-31')->firstOrFail()->status);

        $entry = $closure->closingJournal()->firstOrFail();

        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $this->revenueId,
            'debit' => 1000.00,
            'credit' => 0.00,
        ]);

        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $this->expenseId,
            'debit' => 0.00,
            'credit' => 400.00,
        ]);

        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $this->retainedId,
            'debit' => 0.00,
            'credit' => 600.00,
        ]);

        $plBalance = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.chart_of_account_id')
            ->where('e.status', 'posted')
            ->whereIn('a.account_type', ['revenue','cost_of_sales','expense'])
            ->selectRaw('COALESCE(SUM(l.debit - l.credit),0) as balance')
            ->value('balance');

        $this->assertEquals(0.0, (float) $plBalance);
    }

    public function test_reopen_reverses_close_and_allows_a_new_close_cycle()
    {
        $migration = require database_path('migrations/2026_09_28_150000_create_fiscal_year_closures_table.php');
        $migration->up();

        $year = FiscalYear::firstOrFail();
        $firstClosure = $this->service->close($year, 1);

        $reopened = $this->service->reopen(
            $year,
            '2026-09-30',
            'Correction required after management review',
            1
        );

        $this->assertSame($firstClosure->id, $reopened->id);
        $this->assertSame('reopened', $reopened->status);
        $this->assertNotNull($reopened->reversal_journal_entry_id);
        $this->assertSame('open', FiscalYear::first()->status);
        $this->assertSame('open', AccountingPeriod::whereDate('ends_on', '2026-12-31')->firstOrFail()->status);

        $reversal = $reopened->reversalJournal()->firstOrFail();
        $this->assertSame($firstClosure->closing_journal_entry_id, $reversal->reverses_entry_id);

        $secondClosure = $this->service->close(FiscalYear::firstOrFail(), 1);

        $this->assertNotSame($firstClosure->id, $secondClosure->id);
        $this->assertSame('closed', $secondClosure->status);
        $this->assertSame(2, FiscalYearClosure::where('fiscal_year_id', $year->id)->count());
        $this->assertSame('600.00', $secondClosure->net_result);
    }

    public function test_draft_journal_blocks_year_end_close()
    {
        $migration = require database_path('migrations/2026_09_28_150000_create_fiscal_year_closures_table.php');
        $migration->up();

        JournalEntry::create([
            'entry_number' => 'JE-DRAFT-CLOSE',
            'entry_date' => '2026-09-20',
            'accounting_period_id' => AccountingPeriod::whereDate('starts_on', '<=', '2026-09-20')
                ->whereDate('ends_on', '>=', '2026-09-20')
                ->firstOrFail()->id,
            'description' => 'Unfinished adjustment',
            'status' => 'draft',
            'created_by' => 1,
        ]);

        $readiness = $this->service->readiness(FiscalYear::firstOrFail());

        $this->assertFalse($readiness['ready']);
        $this->assertStringContainsString(
            'draft journal',
            strtolower(implode(' ', $readiness['blockers']))
        );
    }
}
