<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(){Schema::create('installment_plans',function(Blueprint $table){$table->id();$table->foreignId('booking_id')->constrained()->cascadeOnDelete();$table->string('plan_name');$table->string('frequency')->default('monthly');$table->decimal('total_amount',15,2);$table->decimal('down_payment',15,2)->default(0);$table->decimal('installment_amount',15,2);$table->unsignedInteger('number_of_installments');$table->date('start_date');$table->date('end_date')->nullable();$table->string('status')->default('active');$table->text('notes')->nullable();$table->timestamps();$table->softDeletes();$table->index(['booking_id','status']);});}
 public function down(){Schema::dropIfExists('installment_plans');}
};
