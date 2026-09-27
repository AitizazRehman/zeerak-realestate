<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBankReconciliationsTable extends Migration
{
    public function up()
    {
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->onDelete('restrict');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->onDelete('restrict');
            $table->foreignId('bank_statement_import_id')->unique()->constrained('bank_statement_imports')->onDelete('restrict');
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('statement_opening_balance', 18, 2)->nullable();
            $table->decimal('statement_closing_balance', 18, 2);
            $table->decimal('gl_balance', 18, 2)->default(0);
            $table->decimal('outstanding_deposits', 18, 2)->default(0);
            $table->decimal('outstanding_payments', 18, 2)->default(0);
            $table->decimal('unmatched_bank_deposits', 18, 2)->default(0);
            $table->decimal('unmatched_bank_withdrawals', 18, 2)->default(0);
            $table->decimal('adjusted_bank_balance', 18, 2)->default(0);
            $table->decimal('difference', 18, 2)->default(0);
            $table->enum('status', ['draft', 'completed', 'reopened'])->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();

            $table->index(['bank_account_id', 'to_date']);
            $table->index(['branch_id', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('bank_reconciliations');
    }
}
