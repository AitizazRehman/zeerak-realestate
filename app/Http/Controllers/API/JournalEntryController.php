<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Services\AccountingPeriodService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalEntryController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'from' => 'nullable|date', 'to' => 'nullable|date',
            'status' => 'nullable|in:draft,posted',
        ]);

        $query = JournalEntry::with('lines.account:id,code,name')->orderByDesc('entry_date')->orderByDesc('id');
        if ($request->filled('from')) $query->whereDate('entry_date', '>=', $request->from);
        if ($request->filled('to')) $query->whereDate('entry_date', '<=', $request->to);
        if ($request->filled('status')) $query->where('status', $request->status);

        return response()->json($query->paginate(30));
    }

    public function show(JournalEntry $journalEntry)
    {
        return response()->json(['data' => $journalEntry->load('period', 'lines.account:id,code,name')]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'entry_date' => 'required|date',
            'description' => 'required|string|max:500',
            'lines' => 'required|array|min:2',
            'lines.*.chart_of_account_id' => 'required|integer|exists:chart_of_accounts,id',
            'lines.*.debit' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'lines.*.credit' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'lines.*.description' => 'nullable|string|max:500',
            'lines.*.project_id' => 'nullable|integer|exists:projects,id',
            'lines.*.customer_id' => 'nullable|integer|exists:customers,id',
        ]);

        $entry = DB::transaction(function () use ($data, $request) {
            $entry = JournalEntry::create([
                'entry_number' => 'TMP-'.(string) \Illuminate\Support\Str::uuid(),
                'entry_date' => $data['entry_date'],
                'description' => $data['description'],
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);
            $entry->update(['entry_number' => 'JE-'.str_pad($entry->id, 8, '0', STR_PAD_LEFT)]);
            foreach ($data['lines'] as $line) $entry->lines()->create($line);
            return $entry;
        });

        return response()->json(['data' => $entry->load('lines.account:id,code,name')], 201);
    }

    public function post(JournalEntry $journalEntry, AccountingPeriodService $periods)
    {
        $entry = DB::transaction(function () use ($journalEntry, $periods) {
            $entry = JournalEntry::whereKey($journalEntry->id)->lockForUpdate()->firstOrFail();
            if ($entry->status !== 'draft') {
                throw ValidationException::withMessages(['status' => ['Only draft entries can be posted.']]);
            }

            $period = $periods->requireOpen($entry->entry_date);
            $this->assertBalanced($entry);
            $entry->update([
                'status' => 'posted', 'accounting_period_id' => $period->id,
                'posted_at' => now(), 'posted_by' => auth()->id(),
            ]);
            return $entry;
        });

        return response()->json(['data' => $entry->load('lines.account:id,code,name')]);
    }

    public function reverse(JournalEntry $journalEntry, Request $request, AccountingPeriodService $periods)
    {
        $data = $request->validate([
            'entry_date' => 'required|date',
            'reason' => 'required|string|max:500',
        ]);

        $reversal = DB::transaction(function () use ($journalEntry, $data, $request, $periods) {
            $original = JournalEntry::whereKey($journalEntry->id)->lockForUpdate()->firstOrFail();
            if ($original->source_type) {
                throw ValidationException::withMessages(['status' => ['Reverse this entry through its source payment workflow.']]);
            }
            if ($original->status !== 'posted' || $original->reversal()->exists()) {
                throw ValidationException::withMessages(['status' => ['Only an unreversed posted entry can be reversed.']]);
            }
            if ($data['entry_date'] < $original->entry_date->toDateString()) {
                throw ValidationException::withMessages(['entry_date' => ['A reversal cannot precede the original entry.']]);
            }
            $period = $periods->requireOpen($data['entry_date']);
            $reversal = JournalEntry::create([
                'entry_number' => 'TMP-'.(string) \Illuminate\Support\Str::uuid(),
                'entry_date' => $data['entry_date'], 'description' => $data['reason'],
                'status' => 'posted', 'accounting_period_id' => $period->id,
                'reverses_entry_id' => $original->id, 'created_by' => $request->user()->id,
                'posted_by' => $request->user()->id, 'posted_at' => now(),
            ]);
            $reversal->update(['entry_number' => 'JE-'.str_pad($reversal->id, 8, '0', STR_PAD_LEFT)]);
            foreach ($original->lines as $line) {
                $reversal->lines()->create([
                    'chart_of_account_id' => $line->chart_of_account_id,
                    'debit' => $line->credit, 'credit' => $line->debit,
                    'description' => $line->description,
                    'project_id' => $line->project_id, 'customer_id' => $line->customer_id,
                ]);
            }
            return $reversal;
        });

        return response()->json(['data' => $reversal->load('lines.account:id,code,name')], 201);
    }

    private function assertBalanced(JournalEntry $entry)
    {
        $lines = $entry->lines()->get();
        if ($lines->count() < 2) {
            throw ValidationException::withMessages(['lines' => ['At least two lines are required.']]);
        }

        $accounts = ChartOfAccount::whereIn('id', $lines->pluck('chart_of_account_id'))
            ->withCount('children')->get()->keyBy('id');
        $debits = 0;
        $credits = 0;
        foreach ($lines as $line) {
            $account = $accounts->get($line->chart_of_account_id);
            if (!$account || !$account->is_active || !$account->allow_manual_posting || $account->children_count) {
                throw ValidationException::withMessages(['lines' => ['Each line needs an active posting account with no child accounts.']]);
            }
            $debit = $this->cents($line->debit);
            $credit = $this->cents($line->credit);
            if (($debit > 0) === ($credit > 0)) {
                throw ValidationException::withMessages(['lines' => ['Each line must contain either a debit or a credit.']]);
            }
            $debits += $debit;
            $credits += $credit;
        }
        if ($debits !== $credits) {
            throw ValidationException::withMessages(['lines' => ['Total debits must equal total credits.']]);
        }
    }

    private function cents($amount)
    {
        $parts = explode('.', (string) $amount, 2);
        return ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');
    }
}
