<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountingReportController extends Controller
{
    private function filters(Request $request, $ledger = false)
    {
        return $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'account_id' => ($ledger ? 'required' : 'nullable').'|integer|exists:chart_of_accounts,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'page' => 'nullable|integer|min:1',
        ]);
    }

    private function lines(array $filters)
    {
        $query = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.status', 'posted')->where('e.entry_date', '<=', $filters['to']);

        foreach (['account_id' => 'chart_of_account_id', 'project_id' => 'project_id', 'customer_id' => 'customer_id'] as $key => $column) {
            if (!empty($filters[$key])) $query->where('l.'.$column, $filters[$key]);
        }
        return $query;
    }

    // Database DECIMAL values remain exact when converted to integer cents.
    private function cents($value)
    {
        $value = (string) $value;
        $negative = substr($value, 0, 1) === '-';
        $parts = explode('.', ltrim($value, '-'), 2);
        $cents = (int) $parts[0] * 100 + (int) str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');
        return $negative ? -$cents : $cents;
    }

    private function money($cents)
    {
        $absolute = abs($cents);
        return ($cents < 0 ? '-' : '').intdiv($absolute, 100).'.'.str_pad($absolute % 100, 2, '0', STR_PAD_LEFT);
    }

    private function totals($query)
    {
        $result = $query->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')->first();
        return [$this->cents($result->debit), $this->cents($result->credit)];
    }

    public function options()
    {
        return response()->json([
            'accounts' => DB::table('chart_of_accounts')->select('id', 'code', 'name')->orderBy('code')->get(),
            'projects' => DB::table('projects')->select('id', 'name')->orderBy('name')->get(),
            'customers' => DB::table('customers')->select('id', 'name', 'customer_number')->orderBy('name')->get(),
        ]);
    }

    public function ledger(Request $request)
    {
        $filters = $this->filters($request, true);
        return DB::transaction(function () use ($filters) {
            $base = $this->lines($filters);
            list($openingDebit, $openingCredit) = $this->totals((clone $base)->where('e.entry_date', '<', $filters['from']));
            $opening = $openingDebit - $openingCredit;
            $period = (clone $base)->where('e.entry_date', '>=', $filters['from']);
            list($debit, $credit) = $this->totals(clone $period);

            $rows = (clone $period)->select('l.id', 'e.entry_date', 'e.entry_number', 'e.id as journal_entry_id',
                'e.description as entry_description', 'l.description', 'l.debit', 'l.credit', 'l.project_id', 'l.customer_id')
                ->orderBy('e.entry_date')->orderBy('e.id')->orderBy('l.id')->paginate(50);

            $running = $opening;
            if ($first = $rows->first()) {
                $preceding = (clone $period)->where(function ($query) use ($first) {
                    $query->where('e.entry_date', '<', $first->entry_date)
                        ->orWhere(function ($query) use ($first) {
                            $query->where('e.entry_date', $first->entry_date)->where('e.id', '<', $first->journal_entry_id);
                        })->orWhere(function ($query) use ($first) {
                            $query->where('e.entry_date', $first->entry_date)->where('e.id', $first->journal_entry_id)->where('l.id', '<', $first->id);
                        });
                });
                list($previousDebit, $previousCredit) = $this->totals($preceding);
                $running += $previousDebit - $previousCredit;
            }
            foreach ($rows as $row) {
                $running += $this->cents($row->debit) - $this->cents($row->credit);
                $row->debit = $this->money($this->cents($row->debit));
                $row->credit = $this->money($this->cents($row->credit));
                $row->balance = $this->money($running);
            }

            return response()->json([
                'account' => DB::table('chart_of_accounts')->where('id', $filters['account_id'])->first(),
                'entries' => $rows,
                'summary' => ['opening' => $this->money($opening), 'debit' => $this->money($debit),
                    'credit' => $this->money($credit), 'closing' => $this->money($opening + $debit - $credit)],
            ]);
        });
    }

    public function trialBalance(Request $request)
    {
        $filters = $this->filters($request);
        $rows = $this->lines($filters)->join('chart_of_accounts as a', 'a.id', '=', 'l.chart_of_account_id')
            ->select('a.id', 'a.code', 'a.name')
            ->selectRaw('SUM(CASE WHEN e.entry_date < ? THEN l.debit - l.credit ELSE 0 END) as opening', [$filters['from']])
            ->selectRaw('SUM(CASE WHEN e.entry_date >= ? THEN l.debit ELSE 0 END) as debit', [$filters['from']])
            ->selectRaw('SUM(CASE WHEN e.entry_date >= ? THEN l.credit ELSE 0 END) as credit', [$filters['from']])
            ->groupBy('a.id', 'a.code', 'a.name')->orderBy('a.code')->get();
        $debit = 0;
        $credit = 0;
        foreach ($rows as $row) {
            $closing = $this->cents($row->opening) + $this->cents($row->debit) - $this->cents($row->credit);
            foreach (['opening', 'debit', 'credit'] as $field) $row->$field = $this->money($this->cents($row->$field));
            $row->closing_debit = $this->money(max(0, $closing));
            $row->closing_credit = $this->money(max(0, -$closing));
            $debit += max(0, $closing);
            $credit += max(0, -$closing);
        }
        return response()->json(['data' => $rows, 'summary' => [
            'debit' => $this->money($debit), 'credit' => $this->money($credit),
            'difference' => $this->money($debit - $credit), 'balanced' => $debit === $credit,
        ]]);
    }
}
