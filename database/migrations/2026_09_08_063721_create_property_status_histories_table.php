<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePropertyStatusHistoriesTable extends Migration
{
    public function up()
    {
        Schema::create('property_status_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('property_id')
                ->constrained('properties')
                ->cascadeOnDelete();

            $table->string('old_status')->nullable();

            $table->string('new_status');

            $table->foreignId('changed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('property_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('property_status_histories');
    }
}