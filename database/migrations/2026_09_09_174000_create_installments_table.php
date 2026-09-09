<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(){Schema::create('installments',function(Blueprint $table){$table->id();$table->foreignId('installment_plan_id')->constrained()->cascadeOnDelete();$table->foreignId('booking_id')->constrained()->cascadeOnDelete();$table->unsignedInteger('installment_number');$table->date('due_date');$table->decimal('amount',15,2);$table->decimal('paid_amount',15,2)->default(0);$table->decimal('remaining_amount',15,2);$table->string('status')->default('pending');$table->date('paid_date')->nullable();$table->text('notes')->nullable();$table->timestamps();$table->unique(['installment_plan_id','installment_number']);$table->index(['booking_id','status']);});}
 public function down(){Schema::dropIfExists('installments');}
};
