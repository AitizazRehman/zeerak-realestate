<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\JournalEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FixedAssetAccountingService
{
    public function postAcquisition(FixedAsset $asset, $userId)
    {
        if ($asset->acquisition_type !== 'cash_bank') {
            return null;
        }

        return DB::transaction(function () use ($asset, $userId) {
            $asset = FixedAsset::with('category')->whereKey($asset->id)->lockForUpdate()->firstOrFail();

            if ($asset->acquisition_journal_entry_id) {
                return JournalEntry::find($asset->acquisition_journal_entry_id);
            }

            $category = $asset->category;
            $assetAccount = $this->assetAccount($category->asset_account_id);
            $cashAccount = app(PaymentAccountingService::class)
                ->accounts()
                ->firstWhere('id', (int) $asset->acquisition_cash_bank_account_id);

            if (!$cashAccount) {
                $this->fail('Choose an active cash/bank account for asset acquisition.');
            }

            if ((float) $asset->cost <= 0) {
                $this->fail('Asset cost must be greater than zero.');
            }

            $period = app(AccountingPeriodService::class)->requireOpen($asset->purchase_date);

            $entry = $this->entry([
                'entry_date' => $asset->purchase_date->toDateString(),
                'accounting_period_id' => $period->id,
                'description' => 'Fixed asset acquisition '.$asset->asset_number.': '.$asset->name,
                'source_type' => 'fixed_asset_acquisition',
                'source_id' => $asset->id,
            ], $userId);

            $dimensions = [
                'project_id' => $asset->project_id,
                'customer_id' => null,
                'description' => $asset->asset_number,
            ];

            $entry->lines()->create($dimensions + [
                'chart_of_account_id' => $assetAccount->id,
                'debit' => $asset->cost,
                'credit' => '0.00',
            ]);

            $entry->lines()->create($dimensions + [
                'chart_of_account_id' => $cashAccount->id,
                'debit' => '0.00',
                'credit' => $asset->cost,
            ]);

            $asset->update([
                'acquisition_journal_entry_id' => $entry->id,
            ]);

            return $entry;
        });
    }

    public function postDepreciation(FixedAsset $asset, AccountingPeriod $period, $userId)
    {
        return DB::transaction(function () use ($asset, $period, $userId) {
            $asset = FixedAsset::with('category')->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $period = AccountingPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();

            if ($period->status !== 'open') {
                $this->fail('Depreciation can only be posted into an open accounting period.');
            }

            if ($asset->status === 'disposed' && $asset->disposed_on && $period->starts_on->gt($asset->disposed_on)) {
                $this->fail('Depreciation cannot be posted after asset disposal.');
            }

            if ($period->ends_on->lt($asset->in_service_date)) {
                $this->fail('The asset was not yet in service during this accounting period.');
            }

            $existing = FixedAssetDepreciation::where('fixed_asset_id', $asset->id)
                ->where('accounting_period_id', $period->id)
                ->first();

            if ($existing && !$existing->reversed_at) {
                return $existing;
            }

            if ($existing && $existing->reversed_at) {
                $this->fail('A reversed depreciation record already exists for this asset and period.');
            }

            $amount = $this->depreciationAmount($asset);

            if ($amount <= 0.009) {
                $this->fail('The asset is fully depreciated to its residual value.');
            }

            $period = app(AccountingPeriodService::class)->requireOpen($period->ends_on);
            $category = $asset->category;
            $expense = $this->expenseAccount($category->depreciation_expense_account_id);
            $accumulated = $this->accumulatedAccount($category->accumulated_depreciation_account_id);

            $record = FixedAssetDepreciation::create([
                'fixed_asset_id' => $asset->id,
                'accounting_period_id' => $period->id,
                'depreciation_date' => $period->ends_on->toDateString(),
                'amount' => $amount,
                'journal_entry_id' => 0,
                'posted_by' => $userId,
            ]);

            $entry = $this->entry([
                'entry_date' => $period->ends_on->toDateString(),
                'accounting_period_id' => $period->id,
                'description' => 'Depreciation '.$asset->asset_number.' / '.$period->name,
                'source_type' => 'fixed_asset_depreciation',
                'source_id' => $record->id,
            ], $userId);

            $dimensions = [
                'project_id' => $asset->project_id,
                'customer_id' => null,
                'description' => $asset->asset_number,
            ];

            $entry->lines()->create($dimensions + [
                'chart_of_account_id' => $expense->id,
                'debit' => $amount,
                'credit' => '0.00',
            ]);

            $entry->lines()->create($dimensions + [
                'chart_of_account_id' => $accumulated->id,
                'debit' => '0.00',
                'credit' => $amount,
            ]);

            $record->update(['journal_entry_id' => $entry->id]);

            return $record->fresh(['journalEntry.lines']);
        });
    }

    public function reverseDepreciation(FixedAssetDepreciation $depreciation, $date, $reason, $userId)
    {
        return DB::transaction(function () use ($depreciation, $date, $reason, $userId) {
            $depreciation = FixedAssetDepreciation::whereKey($depreciation->id)->lockForUpdate()->firstOrFail();

            if ($depreciation->reversed_at) {
                return $depreciation->reversalJournal;
            }

            $asset = FixedAsset::whereKey($depreciation->fixed_asset_id)->lockForUpdate()->firstOrFail();

            if ($asset->status === 'disposed') {
                $this->fail('Depreciation cannot be reversed after the asset has been disposed.');
            }

            $original = JournalEntry::with('lines')->findOrFail($depreciation->journal_entry_id);

            if ($original->status !== 'posted') {
                $this->fail('Only posted depreciation journals can be reversed.');
            }

            $date = Carbon::parse($date)->toDateString();

            if ($date < $depreciation->depreciation_date->toDateString()) {
                $this->fail('The reversal date cannot precede the depreciation date.');
            }

            $period = app(AccountingPeriodService::class)->requireOpen($date);

            $entry = $this->entry([
                'entry_date' => $date,
                'accounting_period_id' => $period->id,
                'description' => Str::limit('Reversal depreciation '.$asset->asset_number.': '.$reason, 500, ''),
                'source_type' => 'fixed_asset_depreciation_reversal',
                'source_id' => $depreciation->id,
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

            $depreciation->update([
                'reversal_journal_entry_id' => $entry->id,
                'reversed_by' => $userId,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ]);

            return $entry;
        });
    }

    public function dispose(FixedAsset $asset, array $data, $userId)
    {
        return DB::transaction(function () use ($asset, $data, $userId) {
            $asset = FixedAsset::with('category')->whereKey($asset->id)->lockForUpdate()->firstOrFail();

            if ($asset->status === 'disposed') {
                return $asset->disposalJournal;
            }

            $date = Carbon::parse($data['disposed_on'])->toDateString();

            if ($date < $asset->in_service_date->toDateString()) {
                $this->fail('Disposal date cannot precede the in-service date.');
            }

            if (FixedAssetDepreciation::where('fixed_asset_id', $asset->id)
                ->whereNull('reversed_at')
                ->whereDate('depreciation_date', '>', $date)
                ->exists()) {
                $this->fail('Reverse depreciation posted after the proposed disposal date first.');
            }

            $period = app(AccountingPeriodService::class)->requireOpen($date);
            $category = $asset->category;
            $assetAccount = $this->assetAccount($category->asset_account_id);
            $accumulatedAccount = $this->accumulatedAccount($category->accumulated_depreciation_account_id);

            $accumulated = round((float) FixedAssetDepreciation::where('fixed_asset_id', $asset->id)
                ->whereNull('reversed_at')
                ->whereDate('depreciation_date', '<=', $date)
                ->sum('amount'), 2);

            $nbv = max(0, round((float) $asset->cost - $accumulated, 2));
            $proceeds = round((float) ($data['disposal_proceeds'] ?? 0), 2);

            $cash = null;
            if ($proceeds > 0) {
                $cash = app(PaymentAccountingService::class)
                    ->accounts()
                    ->firstWhere('id', (int) ($data['disposal_cash_bank_account_id'] ?? 0));

                if (!$cash) {
                    $this->fail('Choose an active cash/bank account when disposal proceeds are greater than zero.');
                }
            }

            $gain = max(0, round($proceeds - $nbv, 2));
            $loss = max(0, round($nbv - $proceeds, 2));

            $gainAccount = $gain > 0 ? $this->accountByCode('4210', 'revenue') : null;
            $lossAccount = $loss > 0 ? $this->accountByCode('6800', 'expense') : null;

            $entry = $this->entry([
                'entry_date' => $date,
                'accounting_period_id' => $period->id,
                'description' => 'Fixed asset disposal '.$asset->asset_number.': '.$asset->name,
                'source_type' => 'fixed_asset_disposal',
                'source_id' => $asset->id,
            ], $userId);

            $dimensions = [
                'project_id' => $asset->project_id,
                'customer_id' => null,
                'description' => $asset->asset_number,
            ];

            if ($proceeds > 0) {
                $entry->lines()->create($dimensions + [
                    'chart_of_account_id' => $cash->id,
                    'debit' => $proceeds,
                    'credit' => '0.00',
                ]);
            }

            if ($accumulated > 0) {
                $entry->lines()->create($dimensions + [
                    'chart_of_account_id' => $accumulatedAccount->id,
                    'debit' => $accumulated,
                    'credit' => '0.00',
                ]);
            }

            if ($loss > 0) {
                $entry->lines()->create($dimensions + [
                    'chart_of_account_id' => $lossAccount->id,
                    'debit' => $loss,
                    'credit' => '0.00',
                ]);
            }

            $entry->lines()->create($dimensions + [
                'chart_of_account_id' => $assetAccount->id,
                'debit' => '0.00',
                'credit' => $asset->cost,
            ]);

            if ($gain > 0) {
                $entry->lines()->create($dimensions + [
                    'chart_of_account_id' => $gainAccount->id,
                    'debit' => '0.00',
                    'credit' => $gain,
                ]);
            }

            $asset->update([
                'status' => 'disposed',
                'disposed_on' => $date,
                'disposal_proceeds' => $proceeds,
                'disposal_cash_bank_account_id' => $cash ? $cash->id : null,
                'disposal_journal_entry_id' => $entry->id,
                'disposal_reason' => $data['disposal_reason'],
                'disposed_by' => $userId,
            ]);

            return [
                'entry' => $entry,
                'accumulated_depreciation' => $accumulated,
                'net_book_value' => $nbv,
                'proceeds' => $proceeds,
                'gain' => $gain,
                'loss' => $loss,
            ];
        });
    }

    public function bookValues(FixedAsset $asset)
    {
        $accumulated = round((float) $asset->depreciations()
            ->whereNull('reversed_at')
            ->sum('amount'), 2);

        $cost = round((float) $asset->cost, 2);
        $residual = round((float) $asset->residual_value, 2);

        return [
            'cost' => $cost,
            'accumulated_depreciation' => $accumulated,
            'net_book_value' => $asset->status === 'disposed'
                ? 0.0
                : max($residual, round($cost - $accumulated, 2)),
            'depreciable_amount' => max(0, round($cost - $residual, 2)),
            'remaining_depreciable' => $asset->status === 'disposed'
                ? 0.0
                : max(0, round($cost - $residual - $accumulated, 2)),
            'monthly_depreciation' => $asset->useful_life_months > 0
                ? round(($cost - $residual) / $asset->useful_life_months, 2)
                : 0.0,
        ];
    }

    private function depreciationAmount(FixedAsset $asset)
    {
        $values = $this->bookValues($asset);

        return min(
            $values['monthly_depreciation'],
            $values['remaining_depreciable']
        );
    }

    private function assetAccount($id)
    {
        $account = ChartOfAccount::whereKey($id)
            ->where('account_type', 'asset')
            ->where('is_active', true)
            ->first();

        if (!$account) $this->fail('Fixed asset category asset account is unavailable.');

        return $account;
    }

    private function accumulatedAccount($id)
    {
        $account = ChartOfAccount::whereKey($id)
            ->where('account_type', 'asset')
            ->where('normal_balance', 'credit')
            ->where('is_active', true)
            ->first();

        if (!$account) $this->fail('Fixed asset category accumulated depreciation account must be an active credit-normal asset account.');

        return $account;
    }

    private function expenseAccount($id)
    {
        $account = ChartOfAccount::whereKey($id)
            ->where('account_type', 'expense')
            ->where('is_active', true)
            ->first();

        if (!$account) $this->fail('Fixed asset category depreciation expense account is unavailable.');

        return $account;
    }

    private function accountByCode($code, $type)
    {
        $account = ChartOfAccount::where('code', $code)
            ->where('account_type', $type)
            ->where('is_active', true)
            ->first();

        if (!$account) {
            $this->fail('Required system account '.$code.' is missing or inactive.');
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

    private function fail($message)
    {
        throw ValidationException::withMessages([
            'accounting' => [$message],
        ]);
    }
}
