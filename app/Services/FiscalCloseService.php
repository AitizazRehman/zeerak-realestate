<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\FiscalYear;
use App\Models\FiscalYearClosure;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FiscalCloseService
{
    public function readiness(FiscalYear $year)
    {
        $year = FiscalYear::with(['periods', 'closures'])->findOrFail($year->id);
        $periods = $year->periods->sortBy('starts_on')->values();
        $finalPeriod = $periods->last();

        $checks = [];
        $blockers = [];
        $warnings = [];

        $checks[] = $this->check(
            'Fiscal year is open',
            $year->status === 'open',
            $year->status === 'open'
                ? 'The fiscal year is open and eligible for close processing.'
                : 'The fiscal year is already closed.'
        );

        if (!$finalPeriod) {
            $blockers[] = 'The fiscal year has no accounting periods.';
            return $this->result($year, null, $checks, $blockers, $warnings);
        }

        $firstPeriod = $periods->first();
        $calendarComplete = $periods->count() === 12
            && $firstPeriod->starts_on->isSameDay($year->starts_on)
            && $finalPeriod->ends_on->isSameDay($year->ends_on);

        if ($calendarComplete) {
            for ($i = 1; $i < $periods->count(); $i++) {
                if (!$periods[$i - 1]->ends_on->copy()->addDay()->isSameDay($periods[$i]->starts_on)) {
                    $calendarComplete = false;
                    break;
                }
            }
        }

        $checks[] = $this->check(
            'Fiscal period calendar is complete',
            $calendarComplete,
            $calendarComplete
                ? 'All 12 accounting periods cover the fiscal year without gaps.'
                : 'The fiscal year must contain 12 contiguous accounting periods covering the full year.'
        );

        if (!$calendarComplete) {
            $blockers[] = 'Repair the fiscal period calendar before closing the fiscal year.';
        }

        $earlierOpen = $periods->slice(0, max(0, $periods->count() - 1))
            ->where('status', 'open')
            ->values();

        $checks[] = $this->check(
            'Prior periods are closed',
            $earlierOpen->isEmpty(),
            $earlierOpen->isEmpty()
                ? 'All periods before the final period are closed.'
                : $earlierOpen->count().' earlier period(s) are still open.'
        );

        if ($earlierOpen->isNotEmpty()) {
            $blockers[] = 'Close all accounting periods before the final period.';
        }

        $checks[] = $this->check(
            'Final period is open',
            $finalPeriod->status === 'open',
            $finalPeriod->status === 'open'
                ? $finalPeriod->name.' is open for the year-end closing journal.'
                : $finalPeriod->name.' must be reopened before year-end close.'
        );

        if ($finalPeriod->status !== 'open') {
            $blockers[] = 'The final accounting period must be open to post the closing journal.';
        }

        $activeClosure = FiscalYearClosure::where('fiscal_year_id', $year->id)
            ->where('status', 'closed')
            ->latest('id')
            ->first();

        $checks[] = $this->check(
            'No active year-end close exists',
            !$activeClosure,
            $activeClosure
                ? 'This fiscal year already has an active year-end close.'
                : 'No active closing record exists.'
        );

        if ($activeClosure) {
            $blockers[] = 'The fiscal year is already closed through the year-end process.';
        }

        $draftCount = JournalEntry::where('status', 'draft')
            ->whereDate('entry_date', '>=', $year->starts_on->toDateString())
            ->whereDate('entry_date', '<=', $year->ends_on->toDateString())
            ->count();

        $checks[] = $this->check(
            'No draft journals remain',
            $draftCount === 0,
            $draftCount === 0
                ? 'No draft journal entries remain in the fiscal year.'
                : $draftCount.' draft journal entry/entries remain.'
        );

        if ($draftCount > 0) {
            $blockers[] = 'Post or remove all draft journal entries before closing the fiscal year.';
        }

        $trial = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.status', 'posted')
            ->whereDate('e.entry_date', '>=', $year->starts_on->toDateString())
            ->whereDate('e.entry_date', '<=', $year->ends_on->toDateString())
            ->selectRaw('COALESCE(SUM(l.debit),0) as debit, COALESCE(SUM(l.credit),0) as credit')
            ->first();

        $trialDifference = round((float) $trial->debit - (float) $trial->credit, 2);

        $checks[] = $this->check(
            'Trial balance is balanced',
            abs($trialDifference) <= 0.01,
            abs($trialDifference) <= 0.01
                ? 'Posted debits and credits are balanced.'
                : 'Trial balance difference is '.number_format($trialDifference, 2, '.', '').'.'
        );

        if (abs($trialDifference) > 0.01) {
            $blockers[] = 'Resolve the trial balance difference before closing.';
        }

        $retained = ChartOfAccount::where('code', '3200')
            ->where('account_type', 'equity')
            ->where('is_active', true)
            ->first();

        $checks[] = $this->check(
            'Retained Earnings account is available',
            (bool) $retained,
            $retained
                ? 'Account 3200 Retained Earnings is available.'
                : 'Account 3200 Retained Earnings is missing or inactive.'
        );

        if (!$retained) {
            $blockers[] = 'Create or activate account 3200 Retained Earnings.';
        }

        $profitLoss = $this->profitLossBalances($year);
        $netResult = round((float) $profitLoss->sum(function ($row) {
            return $row['account_type'] === 'revenue'
                ? $row['credit'] - $row['debit']
                : -($row['debit'] - $row['credit']);
        }), 2);

        if (Schema::hasTable('bank_transactions')) {
            $unreconciled = DB::table('bank_transactions')
                ->whereDate('transaction_date', '>=', $year->starts_on->toDateString())
                ->whereDate('transaction_date', '<=', $year->ends_on->toDateString())
                ->where('reconciliation_status', '!=', 'reconciled')
                ->count();

            if ($unreconciled > 0) {
                $warnings[] = $unreconciled.' bank statement transaction(s) in the fiscal year are not finalized as reconciled.';
            }
        }

        if (Schema::hasTable('tax_transactions')) {
            $pendingTaxCertificates = DB::table('tax_transactions')
                ->where('status', 'active')
                ->where('certificate_status', 'pending')
                ->whereDate('transaction_date', '>=', $year->starts_on->toDateString())
                ->whereDate('transaction_date', '<=', $year->ends_on->toDateString())
                ->count();

            if ($pendingTaxCertificates > 0) {
                $warnings[] = $pendingTaxCertificates.' withholding certificate(s) are still pending for the fiscal year.';
            }
        }

        if (Schema::hasTable('fixed_assets') && Schema::hasTable('fixed_asset_depreciations')) {
            $pendingDepreciation = DB::table('fixed_assets as fa')
                ->where('fa.status', 'active')
                ->whereDate('fa.in_service_date', '<=', $finalPeriod->ends_on->toDateString())
                ->whereNotExists(function ($query) use ($finalPeriod) {
                    $query->select(DB::raw(1))
                        ->from('fixed_asset_depreciations as fad')
                        ->whereColumn('fad.fixed_asset_id', 'fa.id')
                        ->where('fad.accounting_period_id', $finalPeriod->id)
                        ->whereNull('fad.reversed_at');
                })
                ->count();

            if ($pendingDepreciation > 0) {
                $warnings[] = $pendingDepreciation.' active fixed asset(s) have no depreciation posting in the final period.';
            }
        }

        return $this->result($year, $finalPeriod, $checks, $blockers, $warnings, [
            'trial_balance_difference' => number_format($trialDifference, 2, '.', ''),
            'profit_loss_accounts' => $profitLoss->count(),
            'net_result' => number_format($netResult, 2, '.', ''),
        ]);
    }

    public function close(FiscalYear $year, $userId)
    {
        return DB::transaction(function () use ($year, $userId) {
            $year = FiscalYear::whereKey($year->id)->lockForUpdate()->firstOrFail();
            $readiness = $this->readiness($year);

            if (!$readiness['ready']) {
                throw ValidationException::withMessages([
                    'closing' => [$readiness['blockers'][0] ?? 'Fiscal year is not ready to close.'],
                ]);
            }

            $finalPeriod = AccountingPeriod::whereKey($readiness['final_period']['id'])
                ->lockForUpdate()
                ->firstOrFail();

            $retained = ChartOfAccount::where('code', '3200')
                ->where('account_type', 'equity')
                ->where('is_active', true)
                ->firstOrFail();

            $closure = FiscalYearClosure::create([
                'fiscal_year_id' => $year->id,
                'net_result' => $readiness['summary']['net_result'],
                'status' => 'closed',
                'checklist_snapshot' => $readiness,
                'closed_by' => $userId,
                'closed_at' => now(),
            ]);

            $rows = $this->profitLossBalances($year);
            $entry = null;

            if ($rows->count()) {
                $entry = JournalEntry::create([
                    'entry_number' => 'TMP-'.Str::uuid(),
                    'entry_date' => $year->ends_on->toDateString(),
                    'accounting_period_id' => $finalPeriod->id,
                    'description' => 'Year-end close '.$year->name.' to Retained Earnings',
                    'status' => 'posted',
                    'source_type' => 'fiscal_year_close',
                    'source_id' => $closure->id,
                    'created_by' => $userId,
                    'posted_by' => $userId,
                    'posted_at' => now(),
                ]);

                $entry->update([
                    'entry_number' => 'JE-'.str_pad($entry->id, 8, '0', STR_PAD_LEFT),
                ]);

                $totalDebit = 0.0;
                $totalCredit = 0.0;

                foreach ($rows as $row) {
                    $signed = round((float) $row['debit'] - (float) $row['credit'], 2);

                    if (abs($signed) <= 0.009) continue;

                    if ($signed > 0) {
                        $debit = 0.0;
                        $credit = $signed;
                    } else {
                        $debit = abs($signed);
                        $credit = 0.0;
                    }

                    $entry->lines()->create([
                        'chart_of_account_id' => $row['account_id'],
                        'debit' => number_format($debit, 2, '.', ''),
                        'credit' => number_format($credit, 2, '.', ''),
                        'project_id' => null,
                        'customer_id' => null,
                        'description' => 'Close '.$row['code'].' '.$row['name'],
                    ]);

                    $totalDebit = round($totalDebit + $debit, 2);
                    $totalCredit = round($totalCredit + $credit, 2);
                }

                $difference = round($totalDebit - $totalCredit, 2);

                if (abs($difference) > 0.009) {
                    $entry->lines()->create([
                        'chart_of_account_id' => $retained->id,
                        'debit' => $difference < 0 ? abs($difference) : 0,
                        'credit' => $difference > 0 ? $difference : 0,
                        'project_id' => null,
                        'customer_id' => null,
                        'description' => 'Transfer '.$year->name.' result to Retained Earnings',
                    ]);
                }

                $closure->update([
                    'closing_journal_entry_id' => $entry->id,
                ]);
            }

            $finalPeriod->update(['status' => 'closed']);
            $year->update(['status' => 'closed']);

            return $closure->fresh([
                'closingJournal.lines',
                'closedBy:id,name',
                'fiscalYear.periods',
            ]);
        });
    }

    public function reopen(FiscalYear $year, $date, $reason, $userId)
    {
        return DB::transaction(function () use ($year, $date, $reason, $userId) {
            $year = FiscalYear::whereKey($year->id)->lockForUpdate()->firstOrFail();

            $closure = FiscalYearClosure::where('fiscal_year_id', $year->id)
                ->where('status', 'closed')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (!$closure) {
                throw ValidationException::withMessages([
                    'reopen' => ['No active fiscal year closing record is available to reverse.'],
                ]);
            }

            $period = AccountingPeriod::where('fiscal_year_id', $year->id)
                ->orderByDesc('ends_on')
                ->lockForUpdate()
                ->firstOrFail();

            $year->update(['status' => 'open']);
            $period->update(['status' => 'open']);

            $reversal = null;

            if ($closure->closing_journal_entry_id) {
                $original = JournalEntry::with('lines')->findOrFail($closure->closing_journal_entry_id);
                $reversalDate = $date ?: $year->ends_on->toDateString();

                if ($reversalDate < $year->ends_on->toDateString()) {
                    throw ValidationException::withMessages([
                        'reversal_date' => ['The year-end reversal date cannot precede the fiscal year end date.'],
                    ]);
                }

                $reversalPeriod = app(AccountingPeriodService::class)->requireOpen($reversalDate);

                $reversal = JournalEntry::create([
                    'entry_number' => 'TMP-'.Str::uuid(),
                    'entry_date' => $reversalDate,
                    'accounting_period_id' => $reversalPeriod->id,
                    'description' => Str::limit('Reopen '.$year->name.': '.$reason, 500, ''),
                    'status' => 'posted',
                    'source_type' => 'fiscal_year_reopen',
                    'source_id' => $closure->id,
                    'reverses_entry_id' => $original->id,
                    'created_by' => $userId,
                    'posted_by' => $userId,
                    'posted_at' => now(),
                ]);

                $reversal->update([
                    'entry_number' => 'JE-'.str_pad($reversal->id, 8, '0', STR_PAD_LEFT),
                ]);

                foreach ($original->lines as $line) {
                    $reversal->lines()->create([
                        'chart_of_account_id' => $line->chart_of_account_id,
                        'debit' => $line->credit,
                        'credit' => $line->debit,
                        'project_id' => $line->project_id,
                        'customer_id' => $line->customer_id,
                        'description' => $line->description,
                    ]);
                }
            }

            $closure->update([
                'status' => 'reopened',
                'reversal_journal_entry_id' => $reversal ? $reversal->id : null,
                'reopened_by' => $userId,
                'reopened_at' => now(),
                'reopen_reason' => $reason,
            ]);

            return $closure->fresh([
                'closingJournal',
                'reversalJournal.lines',
                'reopenedBy:id,name',
                'fiscalYear.periods',
            ]);
        });
    }

    public function periodReadiness(AccountingPeriod $period)
    {
        $period = AccountingPeriod::with('fiscalYear')->findOrFail($period->id);
        $blockers = [];
        $warnings = [];

        if ($period->status !== 'open') {
            $blockers[] = 'The accounting period is already closed.';
        }

        if ($period->fiscalYear->status !== 'open') {
            $blockers[] = 'The fiscal year must be open.';
        }

        $draftCount = JournalEntry::where('status', 'draft')
            ->whereDate('entry_date', '>=', $period->starts_on->toDateString())
            ->whereDate('entry_date', '<=', $period->ends_on->toDateString())
            ->count();

        if ($draftCount > 0) {
            $blockers[] = $draftCount.' draft journal entry/entries remain in this period.';
        }

        if (Schema::hasTable('bank_transactions')) {
            $unreconciled = DB::table('bank_transactions')
                ->whereDate('transaction_date', '>=', $period->starts_on->toDateString())
                ->whereDate('transaction_date', '<=', $period->ends_on->toDateString())
                ->where('reconciliation_status', '!=', 'reconciled')
                ->count();

            if ($unreconciled > 0) {
                $warnings[] = $unreconciled.' bank transaction(s) in this period are not finalized as reconciled.';
            }
        }

        if (Schema::hasTable('fixed_assets') && Schema::hasTable('fixed_asset_depreciations')) {
            $pending = DB::table('fixed_assets as fa')
                ->where('fa.status', 'active')
                ->whereDate('fa.in_service_date', '<=', $period->ends_on->toDateString())
                ->whereNotExists(function ($query) use ($period) {
                    $query->select(DB::raw(1))
                        ->from('fixed_asset_depreciations as fad')
                        ->whereColumn('fad.fixed_asset_id', 'fa.id')
                        ->where('fad.accounting_period_id', $period->id)
                        ->whereNull('fad.reversed_at');
                })
                ->count();

            if ($pending > 0) {
                $warnings[] = $pending.' active fixed asset(s) have no depreciation posting in this period.';
            }
        }

        return [
            'ready' => empty($blockers),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'period' => [
                'id' => $period->id,
                'name' => $period->name,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
                'status' => $period->status,
            ],
        ];
    }

    private function profitLossBalances(FiscalYear $year)
    {
        return DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.chart_of_account_id')
            ->where('e.status', 'posted')
            ->whereDate('e.entry_date', '>=', $year->starts_on->toDateString())
            ->whereDate('e.entry_date', '<=', $year->ends_on->toDateString())
            ->whereIn('a.account_type', ['revenue','cost_of_sales','expense'])
            ->select('a.id as account_id','a.code','a.name','a.account_type')
            ->selectRaw('COALESCE(SUM(l.debit),0) as debit')
            ->selectRaw('COALESCE(SUM(l.credit),0) as credit')
            ->groupBy('a.id','a.code','a.name','a.account_type')
            ->get()
            ->map(function ($row) {
                return [
                    'account_id' => (int) $row->account_id,
                    'code' => $row->code,
                    'name' => $row->name,
                    'account_type' => $row->account_type,
                    'debit' => round((float) $row->debit, 2),
                    'credit' => round((float) $row->credit, 2),
                ];
            })
            ->filter(function ($row) {
                return abs($row['debit'] - $row['credit']) > 0.009;
            })
            ->values();
    }

    private function check($name, $passed, $message)
    {
        return [
            'name' => $name,
            'passed' => (bool) $passed,
            'message' => $message,
        ];
    }

    private function result(FiscalYear $year, $finalPeriod, array $checks, array $blockers, array $warnings, array $summary = [])
    {
        return [
            'ready' => empty($blockers),
            'fiscal_year' => [
                'id' => $year->id,
                'name' => $year->name,
                'starts_on' => $year->starts_on->toDateString(),
                'ends_on' => $year->ends_on->toDateString(),
                'status' => $year->status,
            ],
            'final_period' => $finalPeriod ? [
                'id' => $finalPeriod->id,
                'name' => $finalPeriod->name,
                'starts_on' => $finalPeriod->starts_on->toDateString(),
                'ends_on' => $finalPeriod->ends_on->toDateString(),
                'status' => $finalPeriod->status,
            ] : null,
            'checks' => $checks,
            'blockers' => array_values($blockers),
            'warnings' => array_values($warnings),
            'summary' => $summary,
        ];
    }
}
