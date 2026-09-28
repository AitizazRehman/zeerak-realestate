<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('fiscal_year_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->unique()->constrained('fiscal_years')->onDelete('restrict');
            $table->unsignedBigInteger('closing_journal_entry_id')->nullable();
            $table->unsignedBigInteger('reversal_journal_entry_id')->nullable();
            $table->decimal('net_result', 18, 2)->default(0);
            $table->enum('status', ['closed','reopened'])->default('closed');
            $table->longText('checklist_snapshot')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();

            $table->foreign('closing_journal_entry_id', 'fy_close_journal_fk')
                ->references('id')->on('journal_entries')->onDelete('restrict');
            $table->foreign('reversal_journal_entry_id', 'fy_reversal_journal_fk')
                ->references('id')->on('journal_entries')->onDelete('restrict');
            $table->unique('closing_journal_entry_id', 'fy_close_journal_uq');
            $table->unique('reversal_journal_entry_id', 'fy_reversal_journal_uq');
            $table->index(['status','closed_at'], 'fy_close_status_date_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('fiscal_year_closures');
    }
};
