<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancialStatementController extends Controller
{
    use ChecksBranchAccess;

    public function options()
    {
        $projects = DB::table('projects')->select('id', 'name', 'deleted_at');
        $customers = DB::table('customers')->select('id', 'name', 'customer_number', 'deleted_at');
        if (!$this->canAccessAllBranches()) {
            $branch = auth()->user()->branch_id;
            abort_unless($branch, 403, 'Your user account is not assigned to a branch.');
            $projects->where('branch_id', $branch);
            $customers->where('branch_id', $branch);
        }
        return response()->json(['projects'=>$projects->orderBy('name')->get(), 'customers'=>$customers->orderBy('name')->get()]);
    }

    private function filters(Request $request)
    {
        $filters = $request->validate([
            'type'=>'required|in:customer,project', 'from'=>'required|date_format:Y-m-d',
            'to'=>'required|date_format:Y-m-d|after_or_equal:from',
            'customer_id'=>'required_if:type,customer|nullable|integer',
            'project_id'=>'required_if:type,project|nullable|integer',
            'page'=>'nullable|integer|min:1',
        ]);
        foreach (['project'=>'projects', 'customer'=>'customers'] as $kind=>$table) {
            if (empty($filters[$kind.'_id'])) continue;
            $entity = DB::table($table)->where('id', $filters[$kind.'_id'])->first();
            abort_unless($entity, 404, ucfirst($kind).' not found.');
            $this->ensureBranchAccess($entity->branch_id);
            $filters[$kind] = ['id'=>$entity->id, 'name'=>$entity->name];
        }
        return $filters;
    }

    private function lines($filters)
    {
        $query = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.chart_of_account_id')
            ->leftJoin('projects as p', 'p.id', '=', 'l.project_id')
            ->leftJoin('customers as c', 'c.id', '=', 'l.customer_id')
            ->where('e.status', 'posted')->whereDate('e.entry_date', '<=', $filters['to']);
        foreach (['project_id','customer_id'] as $field) {
            if (!empty($filters[$field])) $query->where('l.'.$field, $filters[$field]);
        }
        if ($filters['type'] === 'customer') {
            // A customer statement includes their receivable/advance position, not
            // commission costs or the other sides of balanced journals.
            $query->where('a.is_system', true)->whereIn('a.code', ['1200','2300']);
        }
        if (!$this->canAccessAllBranches()) {
            $branch = auth()->user()->branch_id;
            abort_unless($branch, 403, 'Your user account is not assigned to a branch.');
            $query->where(function ($q) use ($branch) { $q->whereNull('l.project_id')->orWhere('p.branch_id', $branch); });
            $query->where(function ($q) use ($branch) { $q->whereNull('l.customer_id')->orWhere('c.branch_id', $branch); });
        }
        return $query;
    }

    private function cents($value)
    {
        $value = (string) $value;
        $parts = explode('.', ltrim($value, '-'), 2);
        $cents = (int) $parts[0] * 100 + (int) str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');
        return substr($value, 0, 1) === '-' ? -$cents : $cents;
    }

    private function money($value)
    {
        return ($value < 0 ? '-' : '').intdiv(abs($value), 100).'.'.str_pad(abs($value) % 100, 2, '0', STR_PAD_LEFT);
    }

    private function accounts($base, $filters)
    {
        $rows = (clone $base)->select('a.id', 'a.code', 'a.name', 'a.account_type')
            ->selectRaw('COALESCE(SUM(CASE WHEN DATE(e.entry_date) < ? THEN l.debit - l.credit ELSE 0 END),0) as opening', [$filters['from']])
            ->selectRaw('COALESCE(SUM(CASE WHEN DATE(e.entry_date) >= ? THEN l.debit ELSE 0 END),0) as debit', [$filters['from']])
            ->selectRaw('COALESCE(SUM(CASE WHEN DATE(e.entry_date) >= ? THEN l.credit ELSE 0 END),0) as credit', [$filters['from']])
            ->groupBy('a.id','a.code','a.name','a.account_type')->orderBy('a.code')->get();
        foreach ($rows as $row) {
            $row->closing = $this->money($this->cents($row->opening) + $this->cents($row->debit) - $this->cents($row->credit));
            foreach (['opening','debit','credit'] as $field) $row->$field = $this->money($this->cents($row->$field));
        }
        return $rows;
    }

    private function summary($accounts)
    {
        $totals = ['receivable'=>0, 'advances'=>0, 'revenue'=>0, 'costs'=>0];
        foreach ($accounts as $account) {
            if ($account->code === '1200') $totals['receivable'] += $this->cents($account->closing);
            if ($account->code === '2300') $totals['advances'] -= $this->cents($account->closing);
            if ($account->account_type === 'revenue') $totals['revenue'] += $this->cents($account->credit) - $this->cents($account->debit);
            if (in_array($account->account_type, ['cost_of_sales','expense'], true)) $totals['costs'] += $this->cents($account->debit) - $this->cents($account->credit);
        }
        $totals['net_position'] = $totals['receivable'] - $totals['advances'];
        $totals['recorded_result'] = $totals['revenue'] - $totals['costs'];
        return array_map(function ($value) { return $this->money($value); }, $totals);
    }

    private function detail($query)
    {
        return $query->select('l.id', 'l.chart_of_account_id', 'a.code', 'a.name as account_name', 'e.id as journal_entry_id',
            'e.entry_date', 'e.entry_number', 'e.source_type', 'e.source_id', 'e.description as entry_description',
            'l.description', 'l.debit', 'l.credit', 'p.name as project_name', 'c.name as customer_name')
            ->orderBy('e.entry_date')->orderBy('e.id')->orderBy('l.id');
    }

    private function formatRow($row, &$balances)
    {
        $id = $row->chart_of_account_id;
        $balances[$id] = ($balances[$id] ?? 0) + $this->cents($row->debit) - $this->cents($row->credit);
        $row->balance = $this->money($balances[$id]);
        $row->debit = $this->money($this->cents($row->debit));
        $row->credit = $this->money($this->cents($row->credit));
        $row->entry_date = substr($row->entry_date, 0, 10);
        return $row;
    }

    public function show(Request $request)
    {
        $filters = $this->filters($request);
        return DB::transaction(function () use ($filters) {
            $base = $this->lines($filters);
            $accounts = $this->accounts($base, $filters);
            $period = (clone $base)->whereDate('e.entry_date', '>=', $filters['from']);
            $rows = $this->detail(clone $period)->paginate(50, ['*'], 'page', $filters['page'] ?? 1);
            $balances = [];
            foreach ($accounts as $account) $balances[$account->id] = $this->cents($account->opening);
            if ($first = $rows->first()) {
                $preceding = (clone $period)->where(function ($q) use ($first) {
                    $q->where('e.entry_date', '<', $first->entry_date)->orWhere(function ($q) use ($first) {
                        $q->where('e.entry_date', $first->entry_date)->where('e.id', '<', $first->journal_entry_id);
                    })->orWhere(function ($q) use ($first) {
                        $q->where('e.entry_date', $first->entry_date)->where('e.id', $first->journal_entry_id)->where('l.id', '<', $first->id);
                    });
                })->select('l.chart_of_account_id')->selectRaw('SUM(l.debit - l.credit) as movement')->groupBy('l.chart_of_account_id')->get();
                foreach ($preceding as $row) $balances[$row->chart_of_account_id] = ($balances[$row->chart_of_account_id] ?? 0) + $this->cents($row->movement);
            }
            foreach ($rows as $row) $this->formatRow($row, $balances);
            return response()->json(['filters'=>$filters, 'accounts'=>$accounts, 'summary'=>$this->summary($accounts), 'entries'=>$rows]);
        });
    }

    public function export(Request $request)
    {
        $filters = $this->filters($request);
        return response()->streamDownload(function () use ($filters) {
            DB::transaction(function () use ($filters) {
                $out = fopen('php://output', 'w');
                // Prefix untrusted text that spreadsheets might interpret as formulas.
                $write = function ($row) use ($out) {
                    fputcsv($out, array_map(function ($cell) {
                        $cell = (string) $cell;
                        return !preg_match('/^-?\d+(\.\d+)?$/', $cell) && preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $cell) ? "'".$cell : $cell;
                    }, $row));
                };
                $write(['Statement', $filters['type'], 'From', $filters['from'], 'To', $filters['to'], 'Currency', 'PKR']);
                $write(['Customer', $filters['customer']['name'] ?? 'All', 'Project', $filters['project']['name'] ?? 'All']);
                $write(['Posted journals only. Balances are signed: positive = debit, negative = credit.']);
                $base = $this->lines($filters);
                $accounts = $this->accounts($base, $filters);
                $write(['Code','Account','Opening','Period debit','Period credit','Closing']);
                $balances = [];
                foreach ($accounts as $account) {
                    $write([$account->code,$account->name,$account->opening,$account->debit,$account->credit,$account->closing]);
                    $balances[$account->id] = $this->cents($account->opening);
                }
                $write([]);
                $write(['Date','Journal','Source','Source ID','Account code','Account','Project','Customer','Description','Debit','Credit','Account balance']);
                $rows = $this->detail((clone $base)->whereDate('e.entry_date', '>=', $filters['from']))->cursor();
                foreach ($rows as $row) {
                    $this->formatRow($row, $balances);
                    $write([$row->entry_date,$row->entry_number,$row->source_type ?: 'manual',$row->source_id,
                        $row->code,$row->account_name,$row->project_name,$row->customer_name,
                        $row->description ?: $row->entry_description,$row->debit,$row->credit,$row->balance]);
                }
                fclose($out);
            });
        }, $filters['type'].'-statement-'.$filters['to'].'.csv', ['Content-Type'=>'text/csv; charset=UTF-8']);
    }
}
