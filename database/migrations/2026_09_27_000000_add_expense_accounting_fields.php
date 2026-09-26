<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddExpenseAccountingFields extends Migration
{
    public function up()
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('expense_account_id')->nullable()->constrained('chart_of_accounts')->onDelete('restrict');
            $table->foreignId('cash_bank_account_id')->nullable()->constrained('chart_of_accounts')->onDelete('restrict');
            $table->timestamp('reversed_at')->nullable()->index();
            $table->date('reversal_date')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->onDelete('restrict');
            $table->text('reversal_reason')->nullable();
        });
    }

    public function down()
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['expense_account_id']);
            $table->dropForeign(['cash_bank_account_id']);
            $table->dropForeign(['reversed_by']);
            $table->dropIndex(['reversed_at']);
            $table->dropColumn(['expense_account_id', 'cash_bank_account_id', 'reversed_at', 'reversal_date', 'reversed_by', 'reversal_reason']);
        });
    }
}
