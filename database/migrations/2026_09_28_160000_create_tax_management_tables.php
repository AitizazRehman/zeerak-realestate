<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('tax_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->enum('tax_type', [
                'withholding_payable',
                'input_tax_receivable',
                'output_tax_payable',
            ]);
            $table->decimal('rate_percent', 8, 4)->default(0);
            $table->unsignedBigInteger('chart_of_account_id');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('certificate_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('chart_of_account_id', 'tax_code_account_fk')
                ->references('id')->on('chart_of_accounts')->onDelete('restrict');
            $table->index(['tax_type','is_active'], 'tax_code_type_active_idx');
            $table->index(['effective_from','effective_to'], 'tax_code_effective_idx');
        });

        Schema::create('tax_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tax_code_id');
            $table->enum('tax_type', [
                'withholding_payable',
                'input_tax_receivable',
                'output_tax_payable',
            ]);
            $table->unsignedBigInteger('chart_of_account_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->unsignedBigInteger('vendor_bill_id')->nullable();
            $table->unsignedBigInteger('vendor_bill_payment_id')->nullable();
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');
            $table->date('transaction_date');
            $table->string('tax_period', 7);
            $table->decimal('taxable_amount', 18, 2);
            $table->decimal('tax_rate_percent', 8, 4);
            $table->decimal('tax_amount', 18, 2);
            $table->decimal('net_amount', 18, 2);
            $table->enum('status', ['active','reversed'])->default('active');
            $table->enum('certificate_status', ['not_required','pending','issued'])->default('not_required');
            $table->string('certificate_number', 100)->nullable()->unique();
            $table->date('certificate_date')->nullable();
            $table->unsignedBigInteger('certificate_issued_by')->nullable();
            $table->dateTime('certificate_issued_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->dateTime('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tax_code_id', 'tax_tx_code_fk')
                ->references('id')->on('tax_codes')->onDelete('restrict');
            $table->foreign('chart_of_account_id', 'tax_tx_account_fk')
                ->references('id')->on('chart_of_accounts')->onDelete('restrict');
            $table->foreign('branch_id', 'tax_tx_branch_fk')
                ->references('id')->on('branches')->onDelete('restrict');
            $table->foreign('vendor_id', 'tax_tx_vendor_fk')
                ->references('id')->on('vendors')->onDelete('restrict');
            $table->foreign('vendor_bill_id', 'tax_tx_bill_fk')
                ->references('id')->on('vendor_bills')->onDelete('restrict');
            $table->foreign('vendor_bill_payment_id', 'tax_tx_payment_fk')
                ->references('id')->on('vendor_bill_payments')->onDelete('restrict');
            $table->foreign('certificate_issued_by', 'tax_tx_cert_user_fk')
                ->references('id')->on('users')->onDelete('set null');
            $table->foreign('reversed_by', 'tax_tx_reversed_by_fk')
                ->references('id')->on('users')->onDelete('set null');

            $table->unique(
                ['vendor_bill_payment_id','tax_code_id'],
                'tax_tx_payment_code_uq'
            );
            $table->index(['tax_period','status'], 'tax_tx_period_status_idx');
            $table->index(['branch_id','transaction_date'], 'tax_tx_branch_date_idx');
            $table->index(['vendor_id','transaction_date'], 'tax_tx_vendor_date_idx');
            $table->index(['tax_code_id','transaction_date'], 'tax_tx_code_date_idx');
            $table->index(['chart_of_account_id','transaction_date'], 'tax_tx_account_date_idx');
        });

        Schema::create('tax_transaction_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tax_transaction_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->decimal('taxable_amount', 18, 2);
            $table->decimal('tax_amount', 18, 2);
            $table->decimal('net_amount', 18, 2);
            $table->timestamps();

            $table->foreign('tax_transaction_id', 'tax_alloc_tx_fk')
                ->references('id')->on('tax_transactions')->onDelete('cascade');
            $table->foreign('project_id', 'tax_alloc_project_fk')
                ->references('id')->on('projects')->onDelete('restrict');
            $table->index(['tax_transaction_id','project_id'], 'tax_alloc_tx_project_idx');
            $table->index(['project_id','tax_transaction_id'], 'tax_alloc_project_tx_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('tax_transaction_allocations');
        Schema::dropIfExists('tax_transactions');
        Schema::dropIfExists('tax_codes');
    }
};
