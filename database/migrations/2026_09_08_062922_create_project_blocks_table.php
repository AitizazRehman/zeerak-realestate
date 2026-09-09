<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProjectBlocksTable extends Migration
{
    public function up()
    {
        Schema::create('project_blocks', function (Blueprint $table) {

            $table->id();

            $table->foreignId('project_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name');

            $table->string('code');

            $table->text('description')->nullable();

            $table->decimal(
                'total_area',
                15,
                2
            )->nullable();

            $table->string('area_unit')
                ->default('Marla');

            $table->unsignedInteger(
                'total_units'
            )->default(0);

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();

            $table->unique([
                'project_id',
                'code'
            ]);

            $table->index('project_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('project_blocks');
    }
}