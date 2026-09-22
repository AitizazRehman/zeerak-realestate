<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['booking_id', 'reference_number', 'status'], 'payments_booking_reference_status_index');
            $table->index(['cheque_number', 'status'], 'payments_cheque_status_index');
            $table->index(['installment_id', 'status'], 'payments_installment_status_index');
        });
    }

    public function down()
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_booking_reference_status_index');
            $table->dropIndex('payments_cheque_status_index');
            $table->dropIndex('payments_installment_status_index');
        });
    }
};
