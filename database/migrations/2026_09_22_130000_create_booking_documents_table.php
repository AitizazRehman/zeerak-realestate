<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
class CreateBookingDocumentsTable extends Migration {
 public function up(){Schema::create('booking_documents',function(Blueprint $table){$table->id();$table->unsignedBigInteger('booking_id');$table->string('document_type',100);$table->string('name');$table->string('file_path');$table->unsignedBigInteger('file_size')->nullable();$table->string('mime_type')->nullable();$table->unsignedBigInteger('uploaded_by')->nullable();$table->text('notes')->nullable();$table->timestamps();$table->foreign('booking_id')->references('id')->on('bookings')->onDelete('cascade');$table->foreign('uploaded_by')->references('id')->on('users')->onDelete('set null');});}
 public function down(){Schema::dropIfExists('booking_documents');}
}