<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePropertiesTable extends Migration
{
    public function up()
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            $table->foreignId('block_id')
                ->constrained('project_blocks')
                ->cascadeOnDelete();

            $table->string('property_number');

            $table->string('property_type')->default('Residential');

            $table->decimal('size', 12, 2);
            $table->string('size_unit')->default('Marla');

            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);

            $table->enum('status', [
                'available',
                'reserved',
                'booked',
                'sold',
                'under_construction',
                'rented',
                'unavailable',
                'cancelled'
            ])->default('available');

            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();

            $table->decimal('covered_area', 12, 2)->nullable();
            $table->string('covered_area_unit')->default('Sq Ft');

            $table->string('address')->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->text('description')->nullable();

            $table->foreignId('assigned_agent_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->boolean('is_featured')->default(false);
            $table->boolean('is_published')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->unique([
                'project_id',
                'block_id',
                'property_number'
            ]);

            $table->index('project_id');
            $table->index('block_id');
            $table->index('status');
            $table->index('is_published');
        });
    }

    public function down()
    {
        Schema::dropIfExists('properties');
    }
}