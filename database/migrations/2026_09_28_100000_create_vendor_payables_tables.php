<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->onDelete('restrict');
            $table->string('vendor_number', 50)->unique();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('tax_number', 100)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_title')->nullable();
            $table->string('account_number', 100)->nullable();
            $table->string('iban', 100)->nullable();
            $table->unsignedInteger('payment_terms_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'is_active'], 'vendors_branch_active_idx');
            $table->index(['name', 'phone'], 'vendors_name_phone_idx');
        });

        Schema::create('vendor_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->onDelete('restrict');
            $table->foreignId('branch_id')->constrained('branches')->onDelete('restrict');
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('restrict');
            $table->string('bill_number', 50)->unique();
            $table->string('vendor_invoice_number', 100)->nullable();
            $table->date('bill_date');
            $table->date('due_date');
            $table->string('description', 500);
            $table->decimal('total_amount', 18, 2);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('remaining_amount', 18, 2);
            $table->enum('status', ['posted', 'partial', 'paid', 'cancelled'])->default('posted');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->date('cancellation_date')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['vendor_id', 'vendor_invoice_number'], 'vendor_bills_vendor_invoice_uq');
            $table->index(['branch_id', 'due_date', 'status'], 'vendor_bills_branch_due_status_idx');
            $table->index(['project_id', 'due_date'], 'vendor_bills_project_due_idx');
        });

        Schema::create('vendor_bill_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_bill_id')->constrained('vendor_bills')->onDelete('restrict');
            $table->foreignId('chart_of_account_id')->constrained('chart_of_accounts')->onDelete('restrict');
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('restrict');
            $table->string('description', 500);
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->index(['vendor_bill_id', 'chart_of_account_id'], 'vendor_bill_lines_bill_account_idx');
            $table->index(['project_id', 'chart_of_account_id'], 'vendor_bill_lines_project_account_idx');
        });

        Schema::create('vendor_bill_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_bill_id')->constrained('vendor_bills')->onDelete('restrict');
            $table->foreignId('cash_bank_account_id')->constrained('chart_of_accounts')->onDelete('restrict');
            $table->string('request_key', 100)->unique();
            $table->decimal('amount', 18, 2);
            $table->date('payment_date');
            $table->enum('payment_method', ['cash','bank_transfer','cheque','online','other'])->default('bank_transfer');
            $table->string('reference_number', 150)->nullable();
            $table->string('cheque_number', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reversed_at')->nullable();
            $table->date('reversal_date')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            $table->index(['vendor_bill_id', 'payment_date'], 'vendor_bill_payments_bill_date_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('vendor_bill_payments');
        Schema::dropIfExists('vendor_bill_lines');
        Schema::dropIfExists('vendor_bills');
        Schema::dropIfExists('vendors');
    }
};
