<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration{public function up(){Schema::create('commissions',function(Blueprint $t){$t->id();$t->foreignId('booking_id')->constrained()->cascadeOnDelete();$t->foreignId('agent_id')->constrained('users')->restrictOnDelete();$t->decimal('percentage',5,2);$t->decimal('base_amount',15,2);$t->decimal('commission_amount',15,2);$t->string('status')->default('pending');$t->date('approved_date')->nullable();$t->date('paid_date')->nullable();$t->text('notes')->nullable();$t->timestamps();$t->index(['agent_id','status']);});}public function down(){Schema::dropIfExists('commissions');}};
