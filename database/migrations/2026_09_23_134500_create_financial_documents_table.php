<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFinancialDocumentsTable extends Migration
{
    public function up()
    {
        Schema::create('financial_documents', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 30);
            $table->unsignedBigInteger('entity_id');
            $table->string('document_type', 100);
            $table->string('name');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_type', 'entity_id'], 'financial_documents_entity_index');
            $table->foreign('uploaded_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('financial_documents');
    }
}
