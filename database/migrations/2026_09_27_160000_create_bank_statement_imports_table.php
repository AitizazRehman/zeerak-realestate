<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBankStatementImportsTable extends Migration
{
    public function up()
    {
        Schema::create('bank_statement_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->onDelete('restrict');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->onDelete('restrict');
            $table->string('original_filename', 255);
            $table->string('stored_path', 500);
            $table->string('file_type', 10);
            $table->string('file_hash', 64);
            $table->unsignedInteger('header_row')->default(1);
            $table->json('column_mapping')->nullable();
            $table->enum('status', ['uploaded', 'imported', 'failed'])->default('uploaded');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->decimal('statement_opening_balance', 18, 2)->nullable();
            $table->decimal('statement_closing_balance', 18, 2)->nullable();
            $table->text('notes')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('imported_at')->nullable();
            $table->timestamps();

            $table->index(['bank_account_id', 'status']);
            $table->index(['branch_id', 'created_at']);
            $table->index('file_hash');
        });
    }

    public function down()
    {
        Schema::dropIfExists('bank_statement_imports');
    }
}
