<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(){
        Schema::create('site_visits', function(Blueprint $table){
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('visit_at');
            $table->string('status')->default('scheduled');
            $table->text('feedback')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['visit_at','status']);
        });
    }
    public function down(){Schema::dropIfExists('site_visits');}
};
