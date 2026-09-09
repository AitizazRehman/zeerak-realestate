<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProjectsTable extends Migration
{
    public function up()
    {
        Schema::create('projects', function (Blueprint $table) {

            $table->id();

            $table->foreignId('branch_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('name');
            $table->string('code')->unique();

            $table->string('project_type')->nullable();

            $table->text('description')->nullable();

            $table->string('location')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->decimal(
                'total_area',
                15,
                2
            )->nullable();

            $table->string('area_unit')
                ->default('Marla');

            $table->date('start_date')->nullable();

            $table->date(
                'expected_completion_date'
            )->nullable();

            $table->date(
                'actual_completion_date'
            )->nullable();

            $table->enum('status', [
                'planning',
                'approved',
                'active',
                'under_construction',
                'completed',
                'on_hold',
                'cancelled'
            ])->default('planning');

            $table->unsignedTinyInteger(
                'construction_progress'
            )->default(0);

            $table->decimal(
                'budget',
                15,
                2
            )->default(0);

            $table->decimal(
                'actual_cost',
                15,
                2
            )->default(0);

            $table->string(
                'cover_image'
            )->nullable();

            $table->boolean('is_featured')
                ->default(false);

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('branch_id');
            $table->index('status');
            $table->index('city');
            $table->index('is_active');
        });
    }

    public function down()
    {
        Schema::dropIfExists('projects');
    }
}