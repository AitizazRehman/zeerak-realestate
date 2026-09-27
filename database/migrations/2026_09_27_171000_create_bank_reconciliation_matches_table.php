<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('bank_reconciliation_matches')) {
            Schema::table('bank_reconciliation_matches', function (Blueprint $table) {
                $table->index(
                    ['bank_reconciliation_id', 'match_method'],
                    'bank_recon_match_rec_method_idx'
                );
            });

            return;
        }

        Schema::create('bank_reconciliation_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_transaction_id')
                ->unique()
                ->constrained('bank_transactions')
                ->onDelete('restrict');

            $table->foreignId('journal_line_id')
                ->unique()
                ->constrained('journal_lines')
                ->onDelete('restrict');

            $table->foreignId('bank_reconciliation_id')
                ->nullable()
                ->constrained('bank_reconciliations')
                ->onDelete('restrict');

            $table->enum('match_method', ['automatic', 'manual']);
            $table->unsignedTinyInteger('confidence_score')->nullable();

            $table->foreignId('matched_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('matched_at');
            $table->timestamps();

            $table->index(
                ['bank_reconciliation_id', 'match_method'],
                'bank_recon_match_rec_method_idx'
            );
        });
    }

    public function down()
    {
        Schema::dropIfExists('bank_reconciliation_matches');
    }
};
