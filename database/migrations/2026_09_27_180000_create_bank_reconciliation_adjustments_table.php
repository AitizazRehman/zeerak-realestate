<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('bank_reconciliation_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_transaction_id')->unique()->constrained('bank_transactions')->onDelete('restrict');
            $table->foreignId('bank_reconciliation_id')->nullable()->constrained('bank_reconciliations')->onDelete('restrict');
            $table->foreignId('offset_account_id')->constrained('chart_of_accounts')->onDelete('restrict');
            $table->foreignId('journal_entry_id')->unique()->constrained('journal_entries')->onDelete('restrict');
            $table->foreignId('reversal_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->onDelete('restrict');
            $table->string('adjustment_type', 50);
            $table->decimal('amount', 18, 2);
            $table->string('direction', 20);
            $table->string('description', 500);
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('restrict');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->onDelete('restrict');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            $table->index(['bank_reconciliation_id', 'adjustment_type'], 'bank_recon_adj_rec_type_idx');
            $table->index(['offset_account_id', 'created_at'], 'bank_recon_adj_offset_date_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('bank_reconciliation_adjustments');
    }
};
