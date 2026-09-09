<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('lead_number')->unique();
            $table->string('name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('source')->nullable();
            $table->string('status')->default('new');
            $table->string('priority')->default('medium');
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('budget', 15, 2)->nullable();
            $table->date('next_follow_up')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'assigned_to']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down() { Schema::dropIfExists('leads'); }
};
