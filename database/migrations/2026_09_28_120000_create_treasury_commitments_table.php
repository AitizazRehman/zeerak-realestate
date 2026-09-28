<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('treasury_commitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->onDelete('restrict');
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('restrict');
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->onDelete('restrict');
            $table->enum('flow_type', ['inflow','outflow']);
            $table->string('category', 100);
            $table->string('title', 255);
            $table->date('expected_date');
            $table->decimal('amount', 18, 2);
            $table->unsignedTinyInteger('probability_percent')->default(100);
            $table->enum('status', ['planned','realized','cancelled'])->default('planned');
            $table->date('realized_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id','expected_date','status'], 'treasury_commit_branch_date_status_idx');
            $table->index(['project_id','expected_date'], 'treasury_commit_project_date_idx');
            $table->index(['flow_type','expected_date'], 'treasury_commit_flow_date_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('treasury_commitments');
    }
};
