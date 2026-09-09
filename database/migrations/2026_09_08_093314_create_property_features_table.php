<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePropertyFeaturesTable extends Migration
{
    public function up()
    {
        Schema::create('property_features', function (Blueprint $table) {
            $table->id();

            $table->foreignId('property_id')
                ->constrained('properties')
                ->cascadeOnDelete();

            $table->string('feature_name');

            $table->string('feature_value')
                ->nullable();

            $table->timestamps();

            $table->unique([
                'property_id',
                'feature_name'
            ]);
        });
    }

    public function down()
    {
        Schema::dropIfExists('property_features');
    }
}