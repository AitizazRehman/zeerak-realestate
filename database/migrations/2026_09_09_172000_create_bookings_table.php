<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(){
        Schema::create('bookings',function(Blueprint $table){
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('booking_number')->unique();
            $table->string('status')->default('reserved');
            $table->decimal('property_price',15,2)->default(0);
            $table->decimal('discount',15,2)->default(0);
            $table->decimal('final_price',15,2)->default(0);
            $table->decimal('paid_amount',15,2)->default(0);
            $table->decimal('remaining_amount',15,2)->default(0);
            $table->date('booking_date');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status','booking_date']);
            $table->index(['customer_id','status']);
            $table->index(['property_id','status']);
        });
    }
    public function down(){Schema::dropIfExists('bookings');}
};
