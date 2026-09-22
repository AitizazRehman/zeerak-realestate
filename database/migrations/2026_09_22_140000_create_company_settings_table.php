<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
class CreateCompanySettingsTable extends Migration {
 public function up(){Schema::create('company_settings',function(Blueprint $table){$table->id();$table->string('company_name')->default('ZeeraK Real Estate & Builders');$table->string('legal_name')->nullable();$table->string('phone')->nullable();$table->string('whatsapp')->nullable();$table->string('email')->nullable();$table->string('website')->nullable();$table->text('address')->nullable();$table->string('city')->nullable();$table->string('ntn')->nullable();$table->string('currency',10)->default('PKR');$table->string('logo_path')->nullable();$table->text('receipt_footer')->nullable();$table->timestamps();});}
 public function down(){Schema::dropIfExists('company_settings');}
}