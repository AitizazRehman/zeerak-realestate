<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Services\FixedAssetAccountingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AccountingTestCase;

class FixedAssetAccountingTest extends AccountingTestCase
{
    private $service;
    private $category;
    private $cashId;
    private $assetAccountId;
    private $accumulatedId;
    private $expenseId;
    private $lossId;

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

        DB::table('branches')->insert([
            'id' => 1,
            'name' => 'Head Office',
            'code' => 'HO',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('projects')->where('id', 1)->update([
            'branch_id' => 1,
        ]);

        $migration = require database_path('migrations/2026_09_28_140000_create_fixed_asset_tables.php');
        $migration->up();

        $this->ensureAccount('1400', 'Fixed Assets', 'asset', 'debit', false, false);
        $this->assetAccountId = $this->ensureAccount('1410', 'Furniture & Equipment', 'asset', 'debit', true, false);
        $this->accumulatedId = $this->ensureAccount('1490', 'Accumulated Depreciation', 'asset', 'credit', false, true);
        $this->expenseId = $this->ensureAccount('6700', 'Depreciation Expense', 'expense', 'debit', true, false);
        $this->ensureAccount('4210', 'Gain on Asset Disposal', 'revenue', 'credit', true, false);
        $this->lossId = $this->ensureAccount('6800', 'Loss on Asset Disposal', 'expense', 'debit', true, false);

        $this->cashId = ChartOfAccount::where('code', '1101')->value('id');

        $this->category = FixedAssetCategory::create([
            'name' => 'Office Equipment',
            'asset_account_id' => $this->assetAccountId,
            'accumulated_depreciation_account_id' => $this->accumulatedId,
            'depreciation_expense_account_id' => $this->expenseId,
            'useful_life_months' => 12,
            'residual_value_percent' => 0,
            'depreciation_method' => 'straight_line',
            'is_active' => true,
        ]);

        $this->service = new FixedAssetAccountingService();
    }

    private function ensureAccount($code, $name, $type, $normal, $manual, $control)
    {
        $existing = ChartOfAccount::where('code', $code)->first();

        if ($existing) {
            $existing->update([
                'name' => $name,
                'account_type' => $type,
                'normal_balance' => $normal,
                'allow_manual_posting' => $manual,
                'is_control_account' => $control,
                'is_system' => true,
                'is_active' => true,
            ]);

            return $existing->id;
        }

        return ChartOfAccount::create([
            'code' => $code,
            'name' => $name,
            'account_type' => $type,
            'normal_balance' => $normal,
            'allow_manual_posting' => $manual,
            'is_control_account' => $control,
            'is_system' => true,
            'is_active' => true,
        ])->id;
    }

    private function createAsset($acquisitionType = 'cash_bank')
    {
        return FixedAsset::create([
            'fixed_asset_category_id' => $this->category->id,
            'branch_id' => 1,
            'project_id' => 1,
            'asset_number' => 'FA-TEST-001',
            'name' => 'Office Laptop',
            'serial_number' => 'SN-001',
            'location' => 'Head Office',
            'purchase_date' => '2026-09-01',
            'in_service_date' => '2026-09-01',
            'cost' => 1200,
            'residual_value' => 0,
            'useful_life_months' => 12,
            'depreciation_method' => 'straight_line',
            'acquisition_type' => $acquisitionType,
            'acquisition_cash_bank_account_id' => $acquisitionType === 'cash_bank' ? $this->cashId : null,
            'status' => 'active',
            'created_by' => 1,
        ]);
    }

    public function test_cash_acquisition_and_monthly_depreciation_post_to_gl()
    {
        $asset = $this->createAsset();
        $acquisition = $this->service->postAcquisition($asset, 1);

        $this->assertNotNull($acquisition);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $acquisition->id,
            'chart_of_account_id' => $this->assetAccountId,
            'debit' => 1200.00,
            'credit' => 0.00,
            'project_id' => 1,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $acquisition->id,
            'chart_of_account_id' => $this->cashId,
            'debit' => 0.00,
            'credit' => 1200.00,
            'project_id' => 1,
        ]);

        $period = AccountingPeriod::where('starts_on', '2026-09-01')->firstOrFail();
        $depreciation = $this->service->postDepreciation($asset, $period, 1);

        $this->assertSame('100.00', $depreciation->amount);

        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $depreciation->journal_entry_id,
            'chart_of_account_id' => $this->expenseId,
            'debit' => 100.00,
            'credit' => 0.00,
            'project_id' => 1,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $depreciation->journal_entry_id,
            'chart_of_account_id' => $this->accumulatedId,
            'debit' => 0.00,
            'credit' => 100.00,
            'project_id' => 1,
        ]);

        $values = $this->service->bookValues($asset->fresh());
        $this->assertSame(100.0, $values['accumulated_depreciation']);
        $this->assertSame(1100.0, $values['net_book_value']);
        $this->assertSame(100.0, $values['monthly_depreciation']);
    }

    public function test_reversed_depreciation_can_be_reposted_for_same_period()
    {
        $asset = $this->createAsset('existing_gl');
        $period = AccountingPeriod::where('starts_on', '2026-09-01')->firstOrFail();

        $first = $this->service->postDepreciation($asset, $period, 1);
        $reversal = $this->service->reverseDepreciation(
            $first,
            '2026-09-30',
            'Correcting depreciation posting',
            1
        );

        $this->assertNotNull($reversal);
        $first->refresh();
        $this->assertNotNull($first->reversed_at);

        $valuesAfterReverse = $this->service->bookValues($asset->fresh());
        $this->assertSame(0.0, $valuesAfterReverse['accumulated_depreciation']);

        $replacement = $this->service->postDepreciation($asset, $period, 1);

        $this->assertNotSame($first->id, $replacement->id);
        $this->assertSame('100.00', $replacement->amount);
        $this->assertSame(2, DB::table('fixed_asset_depreciations')
            ->where('fixed_asset_id', $asset->id)
            ->where('accounting_period_id', $period->id)
            ->count());

        $values = $this->service->bookValues($asset->fresh());
        $this->assertSame(100.0, $values['accumulated_depreciation']);
        $this->assertSame(1100.0, $values['net_book_value']);
    }

    public function test_disposal_removes_cost_and_posts_loss_against_net_book_value()
    {
        $asset = $this->createAsset('existing_gl');
        $period = AccountingPeriod::where('starts_on', '2026-09-01')->firstOrFail();
        $this->service->postDepreciation($asset, $period, 1);

        $result = $this->service->dispose($asset, [
            'disposed_on' => '2026-09-30',
            'disposal_proceeds' => 1000,
            'disposal_cash_bank_account_id' => $this->cashId,
            'disposal_reason' => 'Sold as part of equipment refresh',
        ], 1);

        $entry = $result['entry'];

        $this->assertSame(100.0, $result['accumulated_depreciation']);
        $this->assertSame(1100.0, $result['net_book_value']);
        $this->assertSame(1000.0, $result['proceeds']);
        $this->assertSame(0.0, $result['gain']);
        $this->assertSame(100.0, $result['loss']);

        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $this->cashId,
            'debit' => 1000.00,
            'credit' => 0.00,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $this->accumulatedId,
            'debit' => 100.00,
            'credit' => 0.00,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $this->lossId,
            'debit' => 100.00,
            'credit' => 0.00,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entry->id,
            'chart_of_account_id' => $this->assetAccountId,
            'debit' => 0.00,
            'credit' => 1200.00,
        ]);

        $asset->refresh();
        $this->assertSame('disposed', $asset->status);
        $values = $this->service->bookValues($asset);
        $this->assertSame(0.0, $values['net_book_value']);
        $this->assertSame(0.0, $values['remaining_depreciable']);
    }
}
