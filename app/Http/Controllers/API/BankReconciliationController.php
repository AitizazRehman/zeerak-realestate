<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationMatch;
use App\Models\BankStatementImport;
use App\Models\BankTransaction;
use App\Models\JournalLine;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankReconciliationController extends Controller
{
    use ChecksBranchAccess;

    const AUTO_MATCH_MIN_SCORE = 80;
    const AUTO_MATCH_MIN_GAP = 5;
    const DEFAULT_DATE_WINDOW = 5;

    private function scopeBranch($query)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;

            if (!$branchId) {
                abort(403, 'Your user account is not assigned to a branch.');
            }

            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    private function accessibleImport($id)
    {
        $query = BankStatementImport::query()
            ->where('status', 'imported');

        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;

            if (!$branchId) {
                abort(403, 'Your user account is not assigned to a branch.');
            }

            $query->where('branch_id', $branchId);
        }

        return $query->findOrFail($id);
    }

    private function accessibleTransaction($id)
    {
        $query = BankTransaction::query();

        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;

            if (!$branchId) {
                abort(403, 'Your user account is not assigned to a branch.');
            }

            $query->where('branch_id', $branchId);
        }

        return $query->findOrFail($id);
    }

    public function index(Request $request)
    {
        $request->validate([
            'bank_account_id' => ['nullable','integer'],
            'status' => ['nullable','in:draft,completed,reopened'],
            'per_page' => ['nullable','integer','min:1','max:100'],
        ]);

        $query = $this->scopeBranch(
            BankReconciliation::with([
                'bankAccount:id,branch_id,name,bank_name,account_number,currency',
                'statementImport:id,bank_account_id,original_filename,imported_at',
                'completedBy:id,name',
                'reopenedBy:id,name',
            ])
        );

        if ($request->filled('bank_account_id')) {
            $query->where('bank_account_id', (int) $request->bank_account_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json(
            $query->orderByDesc('to_date')->orderByDesc('id')->paginate(
                min(max((int) $request->get('per_page', 20), 1), 100)
            )
        );
    }

    public function options(Request $request)
    {
        $imports = $this->scopeBranch(
            BankStatementImport::with([
                'bankAccount:id,branch_id,name,bank_name,account_number,currency',
                'reconciliation:id,bank_statement_import_id,status,difference,completed_at',
            ])->where('status', 'imported')
        );

        if ($request->filled('bank_account_id')) {
            $imports->where('bank_account_id', (int) $request->bank_account_id);
        }

        $imports = $imports
            ->whereHas('transactions')
            ->latest('imported_at')
            ->limit(100)
            ->get([
                'id',
                'bank_account_id',
                'branch_id',
                'original_filename',
                'statement_opening_balance',
                'statement_closing_balance',
                'imported_at',
            ]);

        return response()->json([
            'statement_imports' => $imports,
        ]);
    }

    public function workspace(Request $request)
    {
        $data = $request->validate([
            'bank_statement_import_id' => ['required','integer'],
            'statement_closing_balance' => ['nullable','numeric'],
        ]);

        $import = $this->accessibleImport($data['bank_statement_import_id']);
        $period = $this->statementPeriod($import);
        $account = BankAccount::findOrFail($import->bank_account_id);

        $transactions = BankTransaction::with([
                'reconciliationMatch.journalLine.entry:id,entry_number,entry_date,description,status',
                'reconciliationMatch.matchedBy:id,name',
                'customer:id,name,customer_number',
                'project:id,name,code',
            ])
            ->where('bank_statement_import_id', $import->id)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $pool = $this->candidatePool(
            $account,
            Carbon::parse($period['from'])->subDays(self::DEFAULT_DATE_WINDOW)->format('Y-m-d'),
            Carbon::parse($period['to'])->addDays(self::DEFAULT_DATE_WINDOW)->format('Y-m-d')
        );

        $items = $transactions->map(function (BankTransaction $transaction) use ($pool) {
            $item = $transaction->toArray();

            if (!$transaction->reconciliationMatch) {
                $item['suggestions'] = $this->rankCandidates($transaction, $pool)->take(3)->values()->all();
            } else {
                $item['suggestions'] = [];
            }

            return $item;
        })->values();

        $closingBalance = array_key_exists('statement_closing_balance', $data) && $data['statement_closing_balance'] !== null
            ? $data['statement_closing_balance']
            : $import->statement_closing_balance;

        if ($closingBalance === null) {
            $latestBalance = $transactions
                ->filter(function ($transaction) {
                    return $transaction->running_balance !== null;
                })
                ->sortByDesc(function ($transaction) {
                    return $transaction->transaction_date->format('Y-m-d').'-'.str_pad($transaction->id, 12, '0', STR_PAD_LEFT);
                })
                ->first();

            $closingBalance = $latestBalance ? $latestBalance->running_balance : null;
        }

        $summary = $this->calculateSummary(
            $import,
            $period['to'],
            $closingBalance
        );

        $reconciliation = BankReconciliation::with([
                'completedBy:id,name',
                'reopenedBy:id,name',
            ])
            ->where('bank_statement_import_id', $import->id)
            ->first();

        return response()->json([
            'statement_import' => $import->load('bankAccount:id,branch_id,chart_of_account_id,name,bank_name,account_number,currency'),
            'period' => $period,
            'statement_closing_balance' => $closingBalance === null ? null : $this->money($closingBalance),
            'transactions' => $items,
            'summary' => $summary,
            'reconciliation' => $reconciliation,
            'rules' => [
                'auto_match_min_score' => self::AUTO_MATCH_MIN_SCORE,
                'date_window_days' => self::DEFAULT_DATE_WINDOW,
            ],
        ]);
    }

    public function candidates(Request $request, BankTransaction $bankTransaction)
    {
        $transaction = $this->accessibleTransaction($bankTransaction->id);

        if (!$transaction->bank_statement_import_id) {
            abort(422, 'Only statement-import transactions can be reconciled here.');
        }

        if ($transaction->reconciliation_status === 'reconciled') {
            abort(422, 'This transaction belongs to a completed reconciliation. Reopen it first.');
        }

        $data = $request->validate([
            'days' => ['nullable','integer','min:0','max:90'],
        ]);

        $account = BankAccount::findOrFail($transaction->bank_account_id);
        $days = (int) ($data['days'] ?? self::DEFAULT_DATE_WINDOW);
        $date = Carbon::parse($transaction->transaction_date);

        $pool = $this->candidatePool(
            $account,
            $date->copy()->subDays($days)->format('Y-m-d'),
            $date->copy()->addDays($days)->format('Y-m-d')
        );

        return response()->json([
            'transaction' => $transaction,
            'candidates' => $this->rankCandidates($transaction, $pool)->take(50)->values(),
        ]);
    }

    public function autoMatch(Request $request, BankStatementImport $bankStatementImport)
    {
        $import = $this->accessibleImport($bankStatementImport->id);
        $this->assertReconciliationEditable($import);

        $period = $this->statementPeriod($import);
        $account = BankAccount::findOrFail($import->bank_account_id);

        $transactions = BankTransaction::where('bank_statement_import_id', $import->id)
            ->where('reconciliation_status', 'unmatched')
            ->whereDoesntHave('reconciliationMatch')
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $pool = $this->candidatePool(
            $account,
            Carbon::parse($period['from'])->subDays(self::DEFAULT_DATE_WINDOW)->format('Y-m-d'),
            Carbon::parse($period['to'])->addDays(self::DEFAULT_DATE_WINDOW)->format('Y-m-d')
        );

        $used = [];
        $matched = 0;
        $ambiguous = 0;
        $noCandidate = 0;
        $userId = $request->user()->id;
        $reconciliationId = optional($import->reconciliation)->id;

        DB::transaction(function () use (
            $transactions,
            $pool,
            &$used,
            &$matched,
            &$ambiguous,
            &$noCandidate,
            $userId,
            $reconciliationId
        ) {
            foreach ($transactions as $transaction) {
                $ranked = $this->rankCandidates($transaction, $pool)
                    ->reject(function ($candidate) use ($used) {
                        return isset($used[$candidate['journal_line_id']]);
                    })
                    ->values();

                if (!$ranked->count()) {
                    $noCandidate++;
                    continue;
                }

                $best = $ranked->first();
                $second = $ranked->get(1);
                $gap = $second ? ((int) $best['score'] - (int) $second['score']) : 100;

                if ((int) $best['score'] < self::AUTO_MATCH_MIN_SCORE ||
                    $gap < self::AUTO_MATCH_MIN_GAP) {
                    $ambiguous++;
                    continue;
                }

                $lineAlreadyMatched = BankReconciliationMatch::where('journal_line_id', $best['journal_line_id'])
                    ->lockForUpdate()
                    ->exists();

                $transactionAlreadyMatched = BankReconciliationMatch::where('bank_transaction_id', $transaction->id)
                    ->lockForUpdate()
                    ->exists();

                if ($lineAlreadyMatched || $transactionAlreadyMatched) {
                    $ambiguous++;
                    continue;
                }

                BankReconciliationMatch::create([
                    'bank_transaction_id' => $transaction->id,
                    'journal_line_id' => $best['journal_line_id'],
                    'bank_reconciliation_id' => $reconciliationId,
                    'match_method' => 'automatic',
                    'confidence_score' => $best['score'],
                    'matched_by' => $userId,
                    'matched_at' => now(),
                ]);

                $transaction->update([
                    'journal_entry_id' => $best['journal_entry_id'],
                    'reconciliation_status' => 'matched',
                    'match_method' => 'automatic',
                    'reconciled_at' => null,
                    'reconciled_by' => null,
                ]);

                $used[$best['journal_line_id']] = true;
                $matched++;
            }
        });

        return response()->json([
            'message' => $matched.' transaction(s) matched automatically.',
            'summary' => [
                'matched' => $matched,
                'ambiguous' => $ambiguous,
                'no_candidate' => $noCandidate,
            ],
        ]);
    }

    public function match(Request $request, BankTransaction $bankTransaction)
    {
        $transaction = $this->accessibleTransaction($bankTransaction->id);

        if (!$transaction->bank_statement_import_id) {
            abort(422, 'Only statement-import transactions can be reconciled here.');
        }

        $import = $this->accessibleImport($transaction->bank_statement_import_id);
        $this->assertReconciliationEditable($import);

        $data = $request->validate([
            'journal_line_id' => ['required','integer','exists:journal_lines,id'],
        ]);

        $account = BankAccount::findOrFail($transaction->bank_account_id);

        $line = JournalLine::with('entry')
            ->where('chart_of_account_id', $account->chart_of_account_id)
            ->findOrFail($data['journal_line_id']);

        if (!$line->entry || $line->entry->status !== 'posted') {
            throw ValidationException::withMessages([
                'journal_line_id' => ['Only a posted bank-account journal movement can be matched.'],
            ]);
        }

        $this->assertSameMovement($transaction, $line);

        $score = $this->scoreCandidate($transaction, [
            'journal_line_id' => $line->id,
            'journal_entry_id' => $line->journal_entry_id,
            'entry_number' => $line->entry->entry_number,
            'entry_date' => $line->entry->entry_date->format('Y-m-d'),
            'entry_description' => $line->entry->description,
            'line_description' => $line->description,
            'debit' => (float) $line->debit,
            'credit' => (float) $line->credit,
            'project_id' => $line->project_id,
            'customer_id' => $line->customer_id,
        ]);

        DB::transaction(function () use ($transaction, $line, $request, $import, $score) {
            if (BankReconciliationMatch::where('bank_transaction_id', $transaction->id)->lockForUpdate()->exists()) {
                abort(422, 'This bank transaction is already matched.');
            }

            if (BankReconciliationMatch::where('journal_line_id', $line->id)->lockForUpdate()->exists()) {
                abort(422, 'This journal movement is already matched to another bank transaction.');
            }

            BankReconciliationMatch::create([
                'bank_transaction_id' => $transaction->id,
                'journal_line_id' => $line->id,
                'bank_reconciliation_id' => optional($import->reconciliation)->id,
                'match_method' => 'manual',
                'confidence_score' => $score,
                'matched_by' => $request->user()->id,
                'matched_at' => now(),
            ]);

            $transaction->update([
                'journal_entry_id' => $line->journal_entry_id,
                'reconciliation_status' => 'matched',
                'match_method' => 'manual',
                'reconciled_at' => null,
                'reconciled_by' => null,
            ]);
        });

        return response()->json([
            'message' => 'Bank transaction matched successfully.',
        ]);
    }

    public function unmatch(Request $request, BankTransaction $bankTransaction)
    {
        $transaction = $this->accessibleTransaction($bankTransaction->id);

        if (!$transaction->bank_statement_import_id) {
            abort(422, 'Only statement-import transactions can be reconciled here.');
        }

        $import = $this->accessibleImport($transaction->bank_statement_import_id);
        $this->assertReconciliationEditable($import);

        DB::transaction(function () use ($transaction) {
            $match = BankReconciliationMatch::where('bank_transaction_id', $transaction->id)
                ->lockForUpdate()
                ->first();

            if (!$match) {
                abort(422, 'This bank transaction is not matched.');
            }

            $match->delete();

            $transaction->update([
                'journal_entry_id' => null,
                'reconciliation_status' => 'unmatched',
                'match_method' => null,
                'reconciled_at' => null,
                'reconciled_by' => null,
            ]);
        });

        return response()->json([
            'message' => 'Bank transaction unmatched successfully.',
        ]);
    }

    public function finalize(Request $request)
    {
        $data = $request->validate([
            'bank_statement_import_id' => ['required','integer'],
            'statement_opening_balance' => ['nullable','numeric'],
            'statement_closing_balance' => ['required','numeric'],
            'notes' => ['nullable','string','max:5000'],
        ]);

        $import = $this->accessibleImport($data['bank_statement_import_id']);
        $this->assertReconciliationEditable($import);
        $period = $this->statementPeriod($import);

        $summary = $this->calculateSummary(
            $import,
            $period['to'],
            $data['statement_closing_balance']
        );

        if ((int) $summary['unmatched_bank_count'] > 0) {
            throw ValidationException::withMessages([
                'bank_statement_import_id' => ['All statement transactions must be matched before reconciliation can be completed.'],
            ]);
        }

        if (abs((float) $summary['difference']) > 0.01) {
            throw ValidationException::withMessages([
                'statement_closing_balance' => ['The reconciliation difference must be zero before completion. Current difference: PKR '.$summary['difference'].'.'],
            ]);
        }

        $reconciliation = DB::transaction(function () use ($import, $period, $data, $summary, $request) {
            $reconciliation = BankReconciliation::where('bank_statement_import_id', $import->id)
                ->lockForUpdate()
                ->first();

            $payload = [
                'bank_account_id' => $import->bank_account_id,
                'branch_id' => $import->branch_id,
                'bank_statement_import_id' => $import->id,
                'from_date' => $period['from'],
                'to_date' => $period['to'],
                'statement_opening_balance' => $data['statement_opening_balance'] ?? $import->statement_opening_balance,
                'statement_closing_balance' => $data['statement_closing_balance'],
                'gl_balance' => $summary['gl_balance'],
                'outstanding_deposits' => $summary['outstanding_deposits'],
                'outstanding_payments' => $summary['outstanding_payments'],
                'unmatched_bank_deposits' => $summary['unmatched_bank_deposits'],
                'unmatched_bank_withdrawals' => $summary['unmatched_bank_withdrawals'],
                'adjusted_bank_balance' => $summary['adjusted_bank_balance'],
                'difference' => $summary['difference'],
                'status' => 'completed',
                'notes' => $data['notes'] ?? null,
                'completed_by' => $request->user()->id,
                'completed_at' => now(),
                'reopened_by' => null,
                'reopened_at' => null,
                'reopen_reason' => null,
            ];

            if ($reconciliation) {
                $reconciliation->update($payload);
            } else {
                $payload['created_by'] = $request->user()->id;
                $reconciliation = BankReconciliation::create($payload);
            }

            $transactionIds = BankTransaction::where('bank_statement_import_id', $import->id)
                ->pluck('id');

            BankReconciliationMatch::whereIn('bank_transaction_id', $transactionIds)
                ->update(['bank_reconciliation_id' => $reconciliation->id]);

            BankTransaction::whereIn('id', $transactionIds)
                ->where('reconciliation_status', 'matched')
                ->update([
                    'reconciliation_status' => 'reconciled',
                    'reconciled_by' => $request->user()->id,
                    'reconciled_at' => now(),
                ]);

            return $reconciliation;
        });

        return response()->json([
            'message' => 'Bank reconciliation completed successfully.',
            'reconciliation' => $reconciliation->fresh()->load([
                'bankAccount:id,name,bank_name,account_number,currency',
                'statementImport:id,original_filename,imported_at',
                'completedBy:id,name',
            ]),
        ]);
    }

    public function reopen(Request $request, BankReconciliation $bankReconciliation)
    {
        $reconciliation = $this->scopeBranch(BankReconciliation::query())
            ->findOrFail($bankReconciliation->id);

        if ($reconciliation->status !== 'completed') {
            abort(422, 'Only a completed bank reconciliation can be reopened.');
        }

        $data = $request->validate([
            'reason' => ['required','string','min:5','max:2000'],
        ]);

        DB::transaction(function () use ($reconciliation, $request, $data) {
            $reconciliation->update([
                'status' => 'reopened',
                'reopened_by' => $request->user()->id,
                'reopened_at' => now(),
                'reopen_reason' => $data['reason'],
            ]);

            BankTransaction::where('bank_statement_import_id', $reconciliation->bank_statement_import_id)
                ->where('reconciliation_status', 'reconciled')
                ->update([
                    'reconciliation_status' => 'matched',
                    'reconciled_by' => null,
                    'reconciled_at' => null,
                ]);
        });

        return response()->json([
            'message' => 'Bank reconciliation reopened successfully.',
        ]);
    }

    private function assertReconciliationEditable(BankStatementImport $import)
    {
        $reconciliation = BankReconciliation::where('bank_statement_import_id', $import->id)->first();

        if ($reconciliation && $reconciliation->status === 'completed') {
            abort(422, 'This statement belongs to a completed reconciliation. Reopen it before making changes.');
        }
    }

    private function statementPeriod(BankStatementImport $import)
    {
        $period = BankTransaction::where('bank_statement_import_id', $import->id)
            ->selectRaw('MIN(transaction_date) AS from_date, MAX(transaction_date) AS to_date, COUNT(*) AS row_count')
            ->first();

        if (!$period || !(int) $period->row_count || !$period->from_date || !$period->to_date) {
            abort(422, 'This statement import has no bank transactions to reconcile.');
        }

        return [
            'from' => Carbon::parse($period->from_date)->format('Y-m-d'),
            'to' => Carbon::parse($period->to_date)->format('Y-m-d'),
        ];
    }

    private function candidatePool(BankAccount $account, $from, $to)
    {
        return DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->leftJoin('bank_reconciliation_matches as rm', 'rm.journal_line_id', '=', 'l.id')
            ->where('l.chart_of_account_id', $account->chart_of_account_id)
            ->where('e.status', 'posted')
            ->whereBetween('e.entry_date', [$from, $to])
            ->whereNull('rm.id')
            ->where(function ($q) {
                $q->where('l.debit', '>', 0)->orWhere('l.credit', '>', 0);
            })
            ->orderBy('e.entry_date')
            ->orderBy('l.id')
            ->get([
                'l.id as journal_line_id',
                'l.journal_entry_id',
                'l.debit',
                'l.credit',
                'l.description as line_description',
                'l.project_id',
                'l.customer_id',
                'e.entry_number',
                'e.entry_date',
                'e.description as entry_description',
                'e.source_type',
                'e.source_id',
            ]);
    }

    private function rankCandidates(BankTransaction $transaction, Collection $pool)
    {
        $amount = round((float) $transaction->amount, 2);
        $deposit = (float) $transaction->debit > 0;

        return $pool
            ->filter(function ($candidate) use ($amount, $deposit) {
                $candidateAmount = $deposit ? (float) $candidate->debit : (float) $candidate->credit;
                $opposite = $deposit ? (float) $candidate->credit : (float) $candidate->debit;

                return $candidateAmount > 0 &&
                    $opposite <= 0 &&
                    abs(round($candidateAmount, 2) - $amount) <= 0.009;
            })
            ->map(function ($candidate) use ($transaction) {
                $data = [
                    'journal_line_id' => (int) $candidate->journal_line_id,
                    'journal_entry_id' => (int) $candidate->journal_entry_id,
                    'entry_number' => $candidate->entry_number,
                    'entry_date' => Carbon::parse($candidate->entry_date)->format('Y-m-d'),
                    'entry_description' => $candidate->entry_description,
                    'line_description' => $candidate->line_description,
                    'debit' => $this->money($candidate->debit),
                    'credit' => $this->money($candidate->credit),
                    'project_id' => $candidate->project_id ? (int) $candidate->project_id : null,
                    'customer_id' => $candidate->customer_id ? (int) $candidate->customer_id : null,
                    'source_type' => $candidate->source_type,
                    'source_id' => $candidate->source_id,
                ];

                $data['score'] = $this->scoreCandidate($transaction, $data);
                $data['confidence'] = $this->confidenceLabel($data['score']);

                return $data;
            })
            ->sort(function ($a, $b) {
                if ($a['score'] === $b['score']) {
                    if ($a['entry_date'] === $b['entry_date']) {
                        return $a['journal_line_id'] <=> $b['journal_line_id'];
                    }

                    return strcmp($a['entry_date'], $b['entry_date']);
                }

                return $b['score'] <=> $a['score'];
            })
            ->values();
    }

    private function scoreCandidate(BankTransaction $transaction, array $candidate)
    {
        $score = 60;
        $transactionDate = Carbon::parse($transaction->transaction_date);
        $entryDate = Carbon::parse($candidate['entry_date']);
        $days = abs($transactionDate->diffInDays($entryDate, false));

        if ($days === 0) {
            $score += 25;
        } elseif ($days === 1) {
            $score += 20;
        } elseif ($days === 2) {
            $score += 15;
        } elseif ($days === 3) {
            $score += 10;
        } elseif ($days <= 5) {
            $score += 5;
        }

        $haystack = $this->normalizeText(
            ($candidate['entry_number'] ?? '').' '.
            ($candidate['entry_description'] ?? '').' '.
            ($candidate['line_description'] ?? '')
        );

        $referenceMatched = false;

        foreach ([
            $transaction->reference_number,
            $transaction->cheque_number,
            $transaction->external_transaction_id,
        ] as $reference) {
            $needle = $this->normalizeText($reference);

            if (strlen($needle) >= 3 && strpos($haystack, $needle) !== false) {
                $referenceMatched = true;
                break;
            }
        }

        if ($referenceMatched) {
            $score += 15;
        } else {
            $transactionDescription = $this->normalizeText($transaction->description);

            if (strlen($transactionDescription) >= 8 &&
                (strpos($haystack, $transactionDescription) !== false ||
                    strpos($transactionDescription, $haystack) !== false)) {
                $score += 8;
            }
        }

        if ($transaction->project_id &&
            !empty($candidate['project_id']) &&
            (int) $transaction->project_id === (int) $candidate['project_id']) {
            $score += 5;
        }

        if ($transaction->customer_id &&
            !empty($candidate['customer_id']) &&
            (int) $transaction->customer_id === (int) $candidate['customer_id']) {
            $score += 5;
        }

        return min($score, 100);
    }

    private function assertSameMovement(BankTransaction $transaction, JournalLine $line)
    {
        $transactionDeposit = (float) $transaction->debit > 0;
        $transactionAmount = round((float) $transaction->amount, 2);
        $lineAmount = $transactionDeposit ? (float) $line->debit : (float) $line->credit;
        $opposite = $transactionDeposit ? (float) $line->credit : (float) $line->debit;

        if ($lineAmount <= 0 || $opposite > 0 ||
            abs(round($lineAmount, 2) - $transactionAmount) > 0.009) {
            throw ValidationException::withMessages([
                'journal_line_id' => ['The journal movement must have the same amount and deposit/withdrawal direction as the bank transaction.'],
            ]);
        }
    }

    private function calculateSummary(BankStatementImport $import, $toDate, $statementClosingBalance)
    {
        $account = BankAccount::findOrFail($import->bank_account_id);

        $gl = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.chart_of_account_id', $account->chart_of_account_id)
            ->where('e.status', 'posted')
            ->whereDate('e.entry_date', '<=', $toDate)
            ->selectRaw('COALESCE(SUM(l.debit - l.credit),0) AS balance')
            ->first();

        $outstanding = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->leftJoin('bank_reconciliation_matches as rm', 'rm.journal_line_id', '=', 'l.id')
            ->leftJoin('bank_transactions as bt', function ($join) {
                $join->on('bt.id', '=', 'rm.bank_transaction_id')
                    ->whereNull('bt.deleted_at');
            })
            ->where('l.chart_of_account_id', $account->chart_of_account_id)
            ->where('e.status', 'posted')
            ->whereDate('e.entry_date', '<=', $toDate)
            ->where(function ($q) use ($toDate) {
                $q->whereNull('rm.id')
                    ->orWhereNull('bt.id')
                    ->orWhereDate('bt.transaction_date', '>', $toDate);
            })
            ->selectRaw('COALESCE(SUM(l.debit),0) AS deposits')
            ->selectRaw('COALESCE(SUM(l.credit),0) AS payments')
            ->first();

        $bankUnmatched = DB::table('bank_transactions as bt')
            ->leftJoin('bank_reconciliation_matches as rm', 'rm.bank_transaction_id', '=', 'bt.id')
            ->where('bt.bank_statement_import_id', $import->id)
            ->whereNull('bt.deleted_at')
            ->whereNull('rm.id')
            ->selectRaw('COUNT(*) AS row_count')
            ->selectRaw('COALESCE(SUM(bt.debit),0) AS deposits')
            ->selectRaw('COALESCE(SUM(bt.credit),0) AS withdrawals')
            ->first();

        $matchedCount = DB::table('bank_transactions as bt')
            ->join('bank_reconciliation_matches as rm', 'rm.bank_transaction_id', '=', 'bt.id')
            ->where('bt.bank_statement_import_id', $import->id)
            ->whereNull('bt.deleted_at')
            ->count();

        $glBalance = round((float) $gl->balance, 2);
        $outstandingDeposits = round((float) $outstanding->deposits, 2);
        $outstandingPayments = round((float) $outstanding->payments, 2);
        $closing = $statementClosingBalance === null ? null : round((float) $statementClosingBalance, 2);
        $adjusted = $closing === null ? null : round($closing + $outstandingDeposits - $outstandingPayments, 2);
        $difference = $adjusted === null ? null : round($adjusted - $glBalance, 2);

        return [
            'statement_closing_balance' => $closing === null ? null : $this->money($closing),
            'gl_balance' => $this->money($glBalance),
            'outstanding_deposits' => $this->money($outstandingDeposits),
            'outstanding_payments' => $this->money($outstandingPayments),
            'unmatched_bank_deposits' => $this->money($bankUnmatched->deposits),
            'unmatched_bank_withdrawals' => $this->money($bankUnmatched->withdrawals),
            'adjusted_bank_balance' => $adjusted === null ? null : $this->money($adjusted),
            'difference' => $difference === null ? null : $this->money($difference),
            'matched_bank_count' => (int) $matchedCount,
            'unmatched_bank_count' => (int) $bankUnmatched->row_count,
            'balanced' => $difference !== null && abs($difference) <= 0.01,
        ];
    }

    private function normalizeText($value)
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $value));
    }

    private function confidenceLabel($score)
    {
        if ($score >= 90) {
            return 'high';
        }

        if ($score >= self::AUTO_MATCH_MIN_SCORE) {
            return 'good';
        }

        if ($score >= 70) {
            return 'review';
        }

        return 'low';
    }

    private function money($value)
    {
        return number_format((float) $value, 2, '.', '');
    }
}
