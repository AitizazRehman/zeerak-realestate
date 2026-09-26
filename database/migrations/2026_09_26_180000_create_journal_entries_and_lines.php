<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateJournalEntriesAndLines extends Migration
{
    public function up()
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_number', 40)->unique();
            $table->date('entry_date');
            $table->foreignId('accounting_period_id')->nullable()->constrained('accounting_periods')->onDelete('restrict');
            $table->string('description', 500);
            $table->string('status', 12)->default('draft');
            $table->string('source_type', 50)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('reverses_entry_id')->nullable()->constrained('journal_entries')->onDelete('restrict');
            $table->foreignId('created_by')->constrained('users')->onDelete('restrict');
            $table->foreignId('posted_by')->nullable()->constrained('users')->onDelete('restrict');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->index(['entry_date', 'status']);
            $table->unique(['source_type', 'source_id']);
            $table->unique('reverses_entry_id');
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->onDelete('restrict');
            $table->foreignId('chart_of_account_id')->constrained('chart_of_accounts')->onDelete('restrict');
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->string('description', 500)->nullable();
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('restrict');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->onDelete('restrict');
            $table->timestamps();
            $table->index(['chart_of_account_id', 'journal_entry_id']);
            $table->index(['project_id', 'customer_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
    }
}
