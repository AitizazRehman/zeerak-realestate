<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('fixed_asset_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->foreignId('asset_account_id')->constrained('chart_of_accounts')->onDelete('restrict');
            $table->foreignId('accumulated_depreciation_account_id')->constrained('chart_of_accounts')->onDelete('restrict');
            $table->foreignId('depreciation_expense_account_id')->constrained('chart_of_accounts')->onDelete('restrict');
            $table->unsignedInteger('useful_life_months');
            $table->decimal('residual_value_percent', 5, 2)->default(0);
            $table->enum('depreciation_method', ['straight_line'])->default('straight_line');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['is_active','name'], 'fixed_asset_category_active_name_idx');
        });

        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_category_id')->constrained('fixed_asset_categories')->onDelete('restrict');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('restrict');
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('restrict');
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
            $table->foreignId('acquisition_cash_bank_account_id')->nullable()->constrained('chart_of_accounts')->onDelete('restrict');
            $table->foreignId('acquisition_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->onDelete('restrict');
            $table->enum('status', ['active','disposed'])->default('active');
            $table->date('disposed_on')->nullable();
            $table->decimal('disposal_proceeds', 18, 2)->nullable();
            $table->foreignId('disposal_cash_bank_account_id')->nullable()->constrained('chart_of_accounts')->onDelete('restrict');
            $table->foreignId('disposal_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->onDelete('restrict');
            $table->text('disposal_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('disposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id','status'], 'fixed_assets_branch_status_idx');
            $table->index(['project_id','status'], 'fixed_assets_project_status_idx');
            $table->index(['fixed_asset_category_id','status'], 'fixed_assets_category_status_idx');
        });

        Schema::create('fixed_asset_depreciations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->onDelete('restrict');
            $table->foreignId('accounting_period_id')->constrained('accounting_periods')->onDelete('restrict');
            $table->date('depreciation_date');
            $table->decimal('amount', 18, 2);
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained('journal_entries')->onDelete('restrict');
            $table->foreignId('reversal_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->onDelete('restrict');
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            $table->unique(
                ['fixed_asset_id','accounting_period_id'],
                'fixed_asset_depr_asset_period_uq'
            );
            $table->index(['accounting_period_id','reversed_at'], 'fixed_asset_depr_period_reversed_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('fixed_asset_depreciations');
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('fixed_asset_categories');
    }
};
