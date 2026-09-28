<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('vendor_bill_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_bill_payment_id')
                ->constrained('vendor_bill_payments')
                ->onDelete('restrict');
            $table->foreignId('project_id')
                ->nullable()
                ->constrained('projects')
                ->onDelete('restrict');
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->index(
                ['vendor_bill_payment_id', 'project_id'],
                'vendor_payment_alloc_payment_project_idx'
            );
            $table->index(
                ['project_id', 'vendor_bill_payment_id'],
                'vendor_payment_alloc_project_payment_idx'
            );
        });
    }

    public function down()
    {
        Schema::dropIfExists('vendor_bill_payment_allocations');
    }
};
