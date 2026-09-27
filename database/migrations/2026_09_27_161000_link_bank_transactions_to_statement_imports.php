<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBankStatementImportToBankTransactions extends Migration
{
    public function up()
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->foreignId('bank_statement_import_id')
                ->nullable()
                ->after('journal_entry_id')
                ->constrained('bank_statement_imports')
                ->onDelete('restrict');

            $table->index(['bank_statement_import_id', 'transaction_date'], 'bank_tx_import_date_idx');
        });
    }

    public function down()
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropForeign(['bank_statement_import_id']);
            $table->dropIndex('bank_tx_import_date_idx');
            $table->dropColumn('bank_statement_import_id');
        });
    }
}
