<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\TaxCode;
use App\Models\TaxTransaction;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VendorPayableAccountingService
{
    public function debitAccounts()
    {
        return ChartOfAccount::whereIn('account_type', ['asset','expense','cost_of_sales'])
            ->where('is_active', true)
            ->where('is_control_account', false)
            ->where('allow_manual_posting', true)
            ->doesntHave('children')
            ->orderBy('code')
            ->get();
    }

    public function postBill(VendorBill $bill, $userId)
    {
        return DB::transaction(function () use ($bill, $userId) {
            $bill = VendorBill::with('lines')->whereKey($bill->id)->lockForUpdate()->firstOrFail();

            if ($bill->status === 'cancelled') {
                $this->fail('Cancelled vendor bills cannot be posted.');
            }

            if ($entry = $bill->journalEntry()->first()) {
                if ($entry->status !== 'posted') {
                    $this->fail('The existing vendor bill journal is not posted.');
                }
                return $entry;
            }

            if ((float) $bill->total_amount <= 0 || !$bill->lines->count()) {
                $this->fail('Vendor bill must contain at least one positive line.');
            }

            $lineTotal = round((float) $bill->lines->sum('amount'), 2);

            if (abs($lineTotal - round((float) $bill->total_amount, 2)) > 0.01) {
                $this->fail('Vendor bill line total does not match bill total.');
            }

            $allowed = $this->debitAccounts()->keyBy('id');

            foreach ($bill->lines as $line) {
                if (!$allowed->has($line->chart_of_account_id)) {
                    $this->fail('Every vendor bill line must use an active posting-level asset, expense, or cost-of-sales account.');
                }
            }

            $payable = $this->payableAccount();
            $period = app(AccountingPeriodService::class)->requireOpen($bill->bill_date);

            $entry = $this->entry([
                'entry_date' => $bill->bill_date->toDateString(),
                'accounting_period_id' => $period->id,
                'description' => 'Vendor bill '.$bill->bill_number.': '.$bill->description,
                'source_type' => 'vendor_bill',
                'source_id' => $bill->id,
            ], $userId);

            foreach ($bill->lines as $line) {
                $entry->lines()->create([
                    'chart_of_account_id' => $line->chart_of_account_id,
                    'debit' => $line->amount,
                    'credit' => '0.00',
                    'project_id' => $line->project_id ?: $bill->project_id,
                    'customer_id' => null,
                    'description' => $line->description,
                ]);
            }

            foreach ($this->projectTotals($bill) as $projectTotal) {
                $entry->lines()->create([
                    'chart_of_account_id' => $payable->id,
                    'debit' => '0.00',
                    'credit' => $projectTotal['amount'],
                    'project_id' => $projectTotal['project_id'],
                    'customer_id' => null,
                    'description' => $bill->bill_number.' / Vendor #'.$bill->vendor_id,
                ]);
            }

            return $entry;
        });
    }

    public function payBill(VendorBill $bill, array $data, $userId)
    {
        return DB::transaction(function () use ($bill, $data, $userId) {
            $bill = VendorBill::with(['lines','payments.allocations','payments.taxTransactions'])
                ->whereKey($bill->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($bill->status === 'cancelled') {
                $this->fail('Cancelled vendor bills cannot be paid.');
            }

            $accrual = $bill->journalEntry()->first();
            if (!$accrual || $accrual->status !== 'posted' || $accrual->reversal()->exists()) {
                $this->fail('The vendor bill must have an active posted accrual before payment.');
            }

            $requestKey = trim((string) $data['request_key']);
            $amount = round((float) $data['amount'], 2);
            $preparedTaxes = $this->prepareWithholdings(
                $data['withholdings'] ?? [],
                $data['payment_date'],
                $amount
            );

            $existing = VendorBillPayment::where('request_key', $requestKey)->first();

            if ($existing) {
                if ((int) $existing->vendor_bill_id !== (int) $bill->id || $existing->reversed_at) {
                    $this->fail('This payment request key has already been used.');
                }

                if (round((float) $existing->amount, 2) !== $amount ||
                    (int) $existing->cash_bank_account_id !== (int) $data['cash_bank_account_id'] ||
                    $existing->payment_date->toDateString() !== $data['payment_date'] ||
                    !$this->sameWithholdings($existing, $preparedTaxes)) {
                    $this->fail('A payment retry must keep the original amount, date, cash/bank account, and withholding taxes.');
                }

                return $existing;
            }


            if ($amount <= 0 || $amount > round((float) $bill->remaining_amount, 2)) {
                $this->fail('Payment must be positive and cannot exceed the bill remaining amount.');
            }

            $this->notBefore($data['payment_date'], $bill->bill_date->toDateString());
            $this->notBefore($data['payment_date'], $bill->payments()->max('reversal_date'));

            $cash = app(PaymentAccountingService::class)->accounts()->firstWhere('id', (int) $data['cash_bank_account_id']);
            if (!$cash) {
                $this->fail('Choose an active cash/bank posting account.');
            }

            $payable = $this->payableAccount();

            $payment = $bill->payments()->create([
                'cash_bank_account_id' => $cash->id,
                'request_key' => $requestKey,
                'amount' => $amount,
                'payment_date' => $data['payment_date'],
                'payment_method' => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'cheque_number' => $data['cheque_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'paid_by' => $userId,
            ]);

            $period = app(AccountingPeriodService::class)->requireOpen($payment->payment_date);

            $entry = $this->entry([
                'entry_date' => $payment->payment_date->toDateString(),
                'accounting_period_id' => $period->id,
                'description' => 'Vendor payment '.$bill->bill_number.' / payment #'.$payment->id,
                'source_type' => 'vendor_bill_payment',
                'source_id' => $payment->id,
            ], $userId);

            $allocations = $this->allocatePayment($bill, $amount);
            $taxesByAllocation = $this->recordWithholdings(
                $payment,
                $bill,
                $preparedTaxes,
                $allocations,
                $amount
            );

            foreach ($allocations as $index => $allocation) {
                $payment->allocations()->create([
                    'project_id' => $allocation['project_id'],
                    'amount' => $allocation['amount'],
                ]);

                $dimensions = [
                    'project_id' => $allocation['project_id'],
                    'customer_id' => null,
                    'description' => $bill->bill_number.' / Vendor #'.$bill->vendor_id,
                ];

                $entry->lines()->create($dimensions + [
                    'chart_of_account_id' => $payable->id,
                    'debit' => $allocation['amount'],
                    'credit' => '0.00',
                ]);

                $taxTotal = 0.0;

                foreach ($taxesByAllocation[$index] ?? [] as $taxLine) {
                    $taxTotal = round($taxTotal + (float) $taxLine['tax_amount'], 2);

                    $entry->lines()->create($dimensions + [
                        'chart_of_account_id' => $taxLine['chart_of_account_id'],
                        'debit' => '0.00',
                        'credit' => $taxLine['tax_amount'],
                    ]);
                }

                $netCash = round((float) $allocation['amount'] - $taxTotal, 2);

                if ($netCash < -0.009) {
                    $this->fail('Withholding tax allocation exceeds the vendor payment allocation.');
                }

                if ($netCash > 0.009) {
                    $entry->lines()->create($dimensions + [
                        'chart_of_account_id' => $cash->id,
                        'debit' => '0.00',
                        'credit' => number_format($netCash, 2, '.', ''),
                    ]);
                }
            }

            $this->refreshBillBalance($bill);

            return $payment;
        });
    }

    public function reversePayment(VendorBillPayment $payment, $date, $reason, $userId)
    {
        return DB::transaction(function () use ($payment, $date, $reason, $userId) {
            $payment = VendorBillPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $bill = VendorBill::whereKey($payment->vendor_bill_id)->lockForUpdate()->firstOrFail();

            if ($payment->reversed_at) {
                $this->fail('This vendor payment has already been reversed.');
            }

            if ($bill->status === 'cancelled') {
                $this->fail('A cancelled vendor bill payment cannot be reversed independently.');
            }

            $original = $payment->journalEntry()->first();
            if (!$original || $original->status !== 'posted') {
                $this->fail('The vendor payment journal is missing or not posted.');
            }

            if ($original->reversal()->exists()) {
                $this->fail('The vendor payment journal has already been reversed.');
            }

            $this->notBefore($date, $payment->payment_date->toDateString());
            $period = app(AccountingPeriodService::class)->requireOpen($date);

            $entry = $this->entry([
                'entry_date' => $date,
                'accounting_period_id' => $period->id,
                'description' => Str::limit('Reversal vendor payment #'.$payment->id.': '.$reason, 500, ''),
                'source_type' => 'vendor_bill_payment_reverse',
                'source_id' => $payment->id,
                'reverses_entry_id' => $original->id,
            ], $userId);

            foreach ($original->lines as $line) {
                $entry->lines()->create([
                    'chart_of_account_id' => $line->chart_of_account_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'project_id' => $line->project_id,
                    'customer_id' => $line->customer_id,
                    'description' => $line->description,
                ]);
            }

            $payment->update([
                'reversed_at' => now(),
                'reversal_date' => $date,
                'reversed_by' => $userId,
                'reversal_reason' => $reason,
            ]);

            $payment->taxTransactions()
                ->where('status', 'active')
                ->update([
                    'status' => 'reversed',
                    'reversed_by' => $userId,
                    'reversed_at' => now(),
                    'reversal_reason' => $reason,
                    'updated_at' => now(),
                ]);

            $this->refreshBillBalance($bill);

            return $entry;
        });
    }

    public function cancelBill(VendorBill $bill, $date, $reason, $userId)
    {
        return DB::transaction(function () use ($bill, $date, $reason, $userId) {
            $bill = VendorBill::whereKey($bill->id)->lockForUpdate()->firstOrFail();

            if ($bill->status === 'cancelled') {
                return $bill->cancellationJournal()->first();
            }

            if ($bill->payments()->whereNull('reversed_at')->exists()) {
                $this->fail('Reverse all active vendor payments before cancelling the bill.');
            }

            $original = $bill->journalEntry()->first();

            if (!$original || $original->status !== 'posted') {
                $this->fail('The vendor bill accrual journal is missing or not posted.');
            }

            if ($original->reversal()->exists()) {
                $this->fail('The vendor bill accrual has already been reversed.');
            }

            $this->notBefore($date, $bill->bill_date->toDateString());
            $this->notBefore($date, $bill->payments()->max('reversal_date'));
            $period = app(AccountingPeriodService::class)->requireOpen($date);

            $entry = $this->entry([
                'entry_date' => $date,
                'accounting_period_id' => $period->id,
                'description' => Str::limit('Cancel vendor bill '.$bill->bill_number.': '.$reason, 500, ''),
                'source_type' => 'vendor_bill_cancel',
                'source_id' => $bill->id,
                'reverses_entry_id' => $original->id,
            ], $userId);

            foreach ($original->lines as $line) {
                $entry->lines()->create([
                    'chart_of_account_id' => $line->chart_of_account_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'project_id' => $line->project_id,
                    'customer_id' => $line->customer_id,
                    'description' => $line->description,
                ]);
            }

            $bill->update([
                'status' => 'cancelled',
                'cancelled_by' => $userId,
                'cancelled_at' => now(),
                'cancellation_date' => $date,
                'cancellation_reason' => $reason,
                'remaining_amount' => 0,
            ]);

            return $entry;
        });
    }


    private function projectTotals(VendorBill $bill)
    {
        $totals = [];

        foreach ($bill->lines as $line) {
            $projectId = $line->project_id ?: $bill->project_id;
            $key = $projectId === null ? 'general' : 'project_'.$projectId;

            if (!isset($totals[$key])) {
                $totals[$key] = [
                    'project_id' => $projectId ? (int) $projectId : null,
                    'amount' => 0.0,
                ];
            }

            $totals[$key]['amount'] = round(
                $totals[$key]['amount'] + (float) $line->amount,
                2
            );
        }

        return array_values($totals);
    }

    private function allocatePayment(VendorBill $bill, $amount)
    {
        $totals = collect($this->projectTotals($bill));

        $alreadyAllocated = DB::table('vendor_bill_payment_allocations as a')
            ->join('vendor_bill_payments as p', 'p.id', '=', 'a.vendor_bill_payment_id')
            ->where('p.vendor_bill_id', $bill->id)
            ->whereNull('p.reversed_at')
            ->select('a.project_id')
            ->selectRaw('COALESCE(SUM(a.amount), 0) as allocated')
            ->groupBy('a.project_id')
            ->get();

        $allocatedByProject = [];

        foreach ($alreadyAllocated as $row) {
            $key = $row->project_id === null ? 'general' : 'project_'.(int) $row->project_id;
            $allocatedByProject[$key] = round((float) $row->allocated, 2);
        }

        $outstanding = $totals->map(function ($row) use ($allocatedByProject) {
            $key = $row['project_id'] === null ? 'general' : 'project_'.$row['project_id'];
            $used = $allocatedByProject[$key] ?? 0.0;

            return [
                'project_id' => $row['project_id'],
                'balance' => max(0, round((float) $row['amount'] - $used, 2)),
            ];
        })->filter(function ($row) {
            return $row['balance'] > 0.009;
        })->values();

        $totalOutstanding = round((float) $outstanding->sum('balance'), 2);

        if ($totalOutstanding + 0.01 < $amount) {
            $this->fail('Payment exceeds the project-allocated outstanding balance.');
        }

        $remainingPayment = round((float) $amount, 2);
        $allocations = [];

        foreach ($outstanding as $index => $row) {
            if ($remainingPayment <= 0) break;

            $isLast = $index === $outstanding->count() - 1;
            $share = $isLast
                ? $remainingPayment
                : round($amount * ($row['balance'] / $totalOutstanding), 2);

            $share = min($share, $row['balance'], $remainingPayment);

            if ($share <= 0) continue;

            $allocations[] = [
                'project_id' => $row['project_id'],
                'amount' => number_format($share, 2, '.', ''),
            ];

            $remainingPayment = round($remainingPayment - $share, 2);
        }

        if ($remainingPayment > 0.009 && count($allocations)) {
            $last = count($allocations) - 1;
            $allocations[$last]['amount'] = number_format(
                (float) $allocations[$last]['amount'] + $remainingPayment,
                2,
                '.',
                ''
            );
            $remainingPayment = 0.0;
        }

        if ($remainingPayment > 0.009 || !count($allocations)) {
            $this->fail('Unable to allocate the vendor payment across project balances.');
        }

        return $allocations;
    }

    private function prepareWithholdings(array $items, $paymentDate, $grossAmount)
    {
        if (!count($items)) return [];

        $seen = [];
        $prepared = [];
        $totalTax = 0.0;
        $paymentDate = \Carbon\Carbon::parse($paymentDate)->toDateString();

        foreach ($items as $index => $item) {
            $taxCodeId = (int) ($item['tax_code_id'] ?? 0);

            if (!$taxCodeId || isset($seen[$taxCodeId])) {
                $this->fail('Each withholding tax code may appear only once per vendor payment.');
            }

            $seen[$taxCodeId] = true;

            $code = TaxCode::with('account')->whereKey($taxCodeId)
                ->where('tax_type', 'withholding_payable')
                ->where('is_active', true)
                ->first();

            if (!$code) {
                $this->fail('Choose an active withholding tax code.');
            }

            if ($code->effective_from && $paymentDate < $code->effective_from->toDateString()) {
                $this->fail('Withholding tax code '.$code->code.' is not yet effective on the payment date.');
            }

            if ($code->effective_to && $paymentDate > $code->effective_to->toDateString()) {
                $this->fail('Withholding tax code '.$code->code.' has expired for the payment date.');
            }

            if (!$code->account ||
                !$code->account->is_active ||
                $code->account->account_type !== 'liability' ||
                $code->account->normal_balance !== 'credit') {
                $this->fail('Withholding tax code '.$code->code.' must map to an active credit-normal liability account.');
            }

            $taxable = array_key_exists('taxable_amount', $item) && $item['taxable_amount'] !== null
                ? round((float) $item['taxable_amount'], 2)
                : round((float) $grossAmount, 2);

            if ($taxable <= 0 || $taxable > round((float) $grossAmount, 2)) {
                $this->fail('Each withholding taxable amount must be positive and cannot exceed the gross vendor settlement.');
            }

            $rate = round((float) $code->rate_percent, 4);
            $taxAmount = round($taxable * $rate / 100, 2);

            if ($taxAmount <= 0) {
                $this->fail('Withholding tax code '.$code->code.' produces a zero tax amount.');
            }

            $totalTax = round($totalTax + $taxAmount, 2);

            $prepared[] = [
                'code' => $code,
                'taxable_amount' => $taxable,
                'rate_percent' => $rate,
                'tax_amount' => $taxAmount,
            ];
        }

        if ($totalTax >= round((float) $grossAmount, 2)) {
            $this->fail('Total withholding tax must be less than the gross vendor settlement amount.');
        }

        return $prepared;
    }

    private function sameWithholdings(VendorBillPayment $payment, array $prepared)
    {
        $existing = $payment->taxTransactions()
            ->where('status', 'active')
            ->get()
            ->keyBy('tax_code_id');

        if ($existing->count() !== count($prepared)) return false;

        foreach ($prepared as $item) {
            $row = $existing->get($item['code']->id);

            if (!$row ||
                round((float) $row->taxable_amount, 2) !== round((float) $item['taxable_amount'], 2) ||
                round((float) $row->tax_rate_percent, 4) !== round((float) $item['rate_percent'], 4) ||
                round((float) $row->tax_amount, 2) !== round((float) $item['tax_amount'], 2)) {
                return false;
            }
        }

        return true;
    }

    private function recordWithholdings(
        VendorBillPayment $payment,
        VendorBill $bill,
        array $prepared,
        array $allocations,
        $grossAmount
    ) {
        $byAllocation = [];

        foreach ($prepared as $tax) {
            $code = $tax['code'];
            $transaction = TaxTransaction::create([
                'tax_code_id' => $code->id,
                'tax_type' => $code->tax_type,
                'chart_of_account_id' => $code->chart_of_account_id,
                'branch_id' => $bill->branch_id,
                'vendor_id' => $bill->vendor_id,
                'vendor_bill_id' => $bill->id,
                'vendor_bill_payment_id' => $payment->id,
                'source_type' => 'vendor_payment_withholding',
                'source_id' => $payment->id,
                'transaction_date' => $payment->payment_date->toDateString(),
                'tax_period' => $payment->payment_date->format('Y-m'),
                'taxable_amount' => number_format($tax['taxable_amount'], 2, '.', ''),
                'tax_rate_percent' => number_format($tax['rate_percent'], 4, '.', ''),
                'tax_amount' => number_format($tax['tax_amount'], 2, '.', ''),
                'net_amount' => number_format($tax['taxable_amount'] - $tax['tax_amount'], 2, '.', ''),
                'status' => 'active',
                'certificate_status' => $code->certificate_required ? 'pending' : 'not_required',
            ]);

            $remainingTaxable = round((float) $tax['taxable_amount'], 2);
            $remainingTax = round((float) $tax['tax_amount'], 2);

            foreach ($allocations as $index => $allocation) {
                $isLast = $index === count($allocations) - 1;
                $ratio = (float) $grossAmount > 0
                    ? (float) $allocation['amount'] / (float) $grossAmount
                    : 0;

                $taxableShare = $isLast
                    ? $remainingTaxable
                    : min($remainingTaxable, round((float) $tax['taxable_amount'] * $ratio, 2));

                $taxShare = $isLast
                    ? $remainingTax
                    : min($remainingTax, round((float) $tax['tax_amount'] * $ratio, 2));

                $remainingTaxable = round($remainingTaxable - $taxableShare, 2);
                $remainingTax = round($remainingTax - $taxShare, 2);

                $transaction->allocations()->create([
                    'project_id' => $allocation['project_id'],
                    'taxable_amount' => number_format($taxableShare, 2, '.', ''),
                    'tax_amount' => number_format($taxShare, 2, '.', ''),
                    'net_amount' => number_format($taxableShare - $taxShare, 2, '.', ''),
                ]);

                if ($taxShare > 0.009) {
                    $byAllocation[$index][] = [
                        'chart_of_account_id' => $code->chart_of_account_id,
                        'tax_amount' => number_format($taxShare, 2, '.', ''),
                    ];
                }
            }
        }

        return $byAllocation;
    }

    private function refreshBillBalance(VendorBill $bill)
    {
        $paid = round((float) $bill->payments()->whereNull('reversed_at')->sum('amount'), 2);
        $total = round((float) $bill->total_amount, 2);
        $remaining = max(0, round($total - $paid, 2));

        $status = $remaining <= 0.009
            ? 'paid'
            : ($paid > 0 ? 'partial' : 'posted');

        $bill->update([
            'paid_amount' => $paid,
            'remaining_amount' => $remaining,
            'status' => $status,
        ]);
    }

    private function payableAccount()
    {
        $account = ChartOfAccount::where('code', '2100')
            ->where('account_type', 'liability')
            ->where('is_system', true)
            ->where('is_active', true)
            ->first();

        if (!$account) {
            $this->fail('Activate system account 2100 Accounts Payable before posting vendor bills.');
        }

        return $account;
    }

    private function entry(array $data, $userId)
    {
        $entry = JournalEntry::create($data + [
            'entry_number' => 'TMP-'.Str::uuid(),
            'status' => 'posted',
            'created_by' => $userId,
            'posted_by' => $userId,
            'posted_at' => now(),
        ]);

        $entry->update([
            'entry_number' => 'JE-'.str_pad($entry->id, 8, '0', STR_PAD_LEFT),
        ]);

        return $entry;
    }

    private function notBefore($date, $minimum)
    {
        if (!$minimum) return;

        $minimum = \Carbon\Carbon::parse($minimum)->toDateString();

        if ($date < $minimum) {
            $this->fail('Accounting date must be on or after '.$minimum.'.');
        }
    }

    private function fail($message)
    {
        throw ValidationException::withMessages([
            'accounting' => [$message],
        ]);
    }
}
