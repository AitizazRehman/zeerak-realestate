<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // MySQL DDL can leave partially-created tables behind when a later
        // ALTER TABLE / foreign-key statement fails. This migration is new,
        // so a retry should reset only its own incomplete tables.
        Schema::dropIfExists('fixed_asset_depreciations');
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('fixed_asset_categories');

        Schema::create('fixed_asset_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->unsignedBigInteger('asset_account_id');
            $table->unsignedBigInteger('accumulated_depreciation_account_id');
            $table->unsignedBigInteger('depreciation_expense_account_id');
            $table->unsignedInteger('useful_life_months');
            $table->decimal('residual_value_percent', 5, 2)->default(0);
            $table->enum('depreciation_method', ['straight_line'])->default('straight_line');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('asset_account_id', 'fac_asset_account_fk')
                ->references('id')->on('chart_of_accounts')->onDelete('restrict');
            $table->foreign('accumulated_depreciation_account_id', 'fac_accum_dep_account_fk')
                ->references('id')->on('chart_of_accounts')->onDelete('restrict');
            $table->foreign('depreciation_expense_account_id', 'fac_dep_expense_account_fk')
                ->references('id')->on('chart_of_accounts')->onDelete('restrict');

            $table->index(['is_active','name'], 'fixed_asset_category_active_name_idx');
        });

        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fixed_asset_category_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('asset_number', 50)->unique();
            $table->string('name', 255);
            $table->string('serial_number', 150)->nullable();
            $table->string('location', 255)->nullable();
            $table->date('purchase_date');
            $table->date('in_service_date');
            $table->decimal('cost', 18, 2);
            $table->decimal('residual_value', 18, 2)->default(0);
            $table->unsignedInteger('useful_life_months');
            $table->enum('depreciation_method', ['straight_line'])->default('straight_line');
            $table->enum('acquisition_type', ['existing_gl','cash_bank'])->default('existing_gl');
            $table->unsignedBigInteger('acquisition_cash_bank_account_id')->nullable();
            $table->unsignedBigInteger('acquisition_journal_entry_id')->nullable();
            $table->enum('status', ['active','disposed'])->default('active');
            $table->date('disposed_on')->nullable();
            $table->decimal('disposal_proceeds', 18, 2)->nullable();
            $table->unsignedBigInteger('disposal_cash_bank_account_id')->nullable();
            $table->unsignedBigInteger('disposal_journal_entry_id')->nullable();
            $table->text('disposal_reason')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('disposed_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('fixed_asset_category_id', 'fa_category_fk')
                ->references('id')->on('fixed_asset_categories')->onDelete('restrict');
            $table->foreign('branch_id', 'fa_branch_fk')
                ->references('id')->on('branches')->onDelete('restrict');
            $table->foreign('project_id', 'fa_project_fk')
                ->references('id')->on('projects')->onDelete('restrict');
            $table->foreign('acquisition_cash_bank_account_id', 'fa_acq_cash_account_fk')
                ->references('id')->on('chart_of_accounts')->onDelete('restrict');
            $table->foreign('acquisition_journal_entry_id', 'fa_acq_journal_fk')
                ->references('id')->on('journal_entries')->onDelete('restrict');
            $table->foreign('disposal_cash_bank_account_id', 'fa_disp_cash_account_fk')
                ->references('id')->on('chart_of_accounts')->onDelete('restrict');
            $table->foreign('disposal_journal_entry_id', 'fa_disp_journal_fk')
                ->references('id')->on('journal_entries')->onDelete('restrict');
            $table->foreign('created_by', 'fa_created_by_fk')
                ->references('id')->on('users')->onDelete('set null');
            $table->foreign('disposed_by', 'fa_disposed_by_fk')
                ->references('id')->on('users')->onDelete('set null');

            $table->unique('acquisition_journal_entry_id', 'fa_acq_journal_uq');
            $table->unique('disposal_journal_entry_id', 'fa_disp_journal_uq');
            $table->index(['branch_id','status'], 'fixed_assets_branch_status_idx');
            $table->index(['project_id','status'], 'fixed_assets_project_status_idx');
            $table->index(['fixed_asset_category_id','status'], 'fixed_assets_category_status_idx');
        });

        Schema::create('fixed_asset_depreciations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fixed_asset_id');
            $table->unsignedBigInteger('accounting_period_id');
            $table->date('depreciation_date');
            $table->decimal('amount', 18, 2);
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('reversal_journal_entry_id')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->dateTime('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            $table->foreign('fixed_asset_id', 'fad_asset_fk')
                ->references('id')->on('fixed_assets')->onDelete('restrict');
            $table->foreign('accounting_period_id', 'fad_period_fk')
                ->references('id')->on('accounting_periods')->onDelete('restrict');
            $table->foreign('journal_entry_id', 'fad_journal_fk')
                ->references('id')->on('journal_entries')->onDelete('restrict');
            $table->foreign('reversal_journal_entry_id', 'fad_reversal_journal_fk')
                ->references('id')->on('journal_entries')->onDelete('restrict');
            $table->foreign('posted_by', 'fad_posted_by_fk')
                ->references('id')->on('users')->onDelete('set null');
            $table->foreign('reversed_by', 'fad_reversed_by_fk')
                ->references('id')->on('users')->onDelete('set null');

            $table->unique('journal_entry_id', 'fad_journal_uq');
            $table->unique('reversal_journal_entry_id', 'fad_reversal_journal_uq');
            $table->index(
                ['fixed_asset_id','accounting_period_id'],
                'fixed_asset_depr_asset_period_idx'
            );
            $table->index(
                ['accounting_period_id','reversed_at'],
                'fixed_asset_depr_period_reversed_idx'
            );
        });
    }

    public function down()
    {
        Schema::dropIfExists('fixed_asset_depreciations');
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('fixed_asset_categories');
    }
};
