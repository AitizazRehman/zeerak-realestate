<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateChartOfAccountsTable extends Migration
{
    public function up()
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->enum('account_type', ['asset','liability','equity','revenue','cost_of_sales','expense']);
            $table->enum('normal_balance', ['debit','credit']);
            $table->boolean('is_control_account')->default(false);
            $table->boolean('allow_manual_posting')->default(true);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('parent_id')
                ->references('id')
                ->on('chart_of_accounts')
                ->onDelete('restrict');

            $table->index(['account_type','is_active']);
            $table->index(['parent_id','is_active']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('chart_of_accounts');
    }
}
