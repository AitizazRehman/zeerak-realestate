<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCashBankAccountToPayments extends Migration
{
    public function up()
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('cash_bank_account_id')->nullable()->constrained('chart_of_accounts')->onDelete('restrict');
        });
    }

    public function down()
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['cash_bank_account_id']);
            $table->dropColumn('cash_bank_account_id');
        });
    }
}
