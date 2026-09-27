<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBankAccountsTable extends Migration
{
    public function up()
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->onDelete('restrict');
            $table->foreignId('chart_of_account_id')->unique()->constrained('chart_of_accounts')->onDelete('restrict');
            $table->enum('account_type', ['bank','cash'])->default('bank');
            $table->string('name', 150);
            $table->string('bank_name', 150)->nullable();
            $table->string('account_title', 150)->nullable();
            $table->string('account_number', 100)->nullable();
            $table->string('iban', 50)->nullable();
            $table->string('bank_branch', 150)->nullable();
            $table->string('currency', 3)->default('PKR');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('account_number');
            $table->unique('iban');
            $table->index(['branch_id','is_active']);
            $table->index(['account_type','is_active']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('bank_accounts');
    }
}
