<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('accounting_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->onDelete('restrict');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->onDelete('restrict');
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('restrict');
            $table->string('name', 150);
            $table->enum('status', ['draft','approved'])->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();

            $table->index(['fiscal_year_id','status'], 'acct_budget_year_status_idx');
            $table->index(['branch_id','project_id'], 'acct_budget_branch_project_idx');
        });

        Schema::create('accounting_budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accounting_budget_id')->constrained('accounting_budgets')->onDelete('cascade');
            $table->foreignId('accounting_period_id')->constrained('accounting_periods')->onDelete('restrict');
            $table->foreignId('chart_of_account_id')->constrained('chart_of_accounts')->onDelete('restrict');
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(
                ['accounting_budget_id','accounting_period_id','chart_of_account_id'],
                'acct_budget_line_period_account_uq'
            );
            $table->index(['accounting_period_id','chart_of_account_id'], 'acct_budget_period_account_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('accounting_budget_lines');
        Schema::dropIfExists('accounting_budgets');
    }
};
