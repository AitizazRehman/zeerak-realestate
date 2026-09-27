<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBankTransactionsTable extends Migration
{
    public function up()
    {
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->onDelete('restrict');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->onDelete('restrict');
            $table->date('transaction_date');
            $table->date('value_date')->nullable();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->decimal('running_balance', 18, 2)->nullable();
            $table->string('reference_number', 150)->nullable();
            $table->string('cheque_number', 100)->nullable();
            $table->string('external_transaction_id', 191)->nullable();
            $table->string('transaction_hash', 64)->nullable();
            $table->text('description')->nullable();
            $table->enum('source', ['manual', 'statement_import', 'system'])->default('manual');
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('restrict');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->onDelete('restrict');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->onDelete('restrict');
            $table->enum('reconciliation_status', ['unmatched', 'matched', 'reconciled'])->default('unmatched');
            $table->enum('match_method', ['manual', 'automatic'])->nullable();
            $table->dateTime('reconciled_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['bank_account_id', 'transaction_date']);
            $table->index(['branch_id', 'transaction_date']);
            $table->index(['reconciliation_status', 'transaction_date']);
            $table->index(['project_id', 'customer_id']);
            $table->index('external_transaction_id');
            $table->index('transaction_hash');
        });
    }

    public function down()
    {
        Schema::dropIfExists('bank_transactions');
    }
}
