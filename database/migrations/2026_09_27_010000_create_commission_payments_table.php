<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCommissionPaymentsTable extends Migration
{
    public function up()
    {
        Schema::create('commission_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_id')->constrained('commissions')->onDelete('restrict');
            $table->foreignId('cash_bank_account_id')->constrained('chart_of_accounts')->onDelete('restrict');
            $table->string('request_key', 80)->unique();
            $table->decimal('amount', 15, 2);
            $table->date('payment_date');
            $table->foreignId('paid_by')->constrained('users')->onDelete('restrict');
            $table->timestamp('reversed_at')->nullable();
            $table->date('reversal_date')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->onDelete('restrict');
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
            $table->index(['commission_id', 'reversed_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('commission_payments');
    }
}
