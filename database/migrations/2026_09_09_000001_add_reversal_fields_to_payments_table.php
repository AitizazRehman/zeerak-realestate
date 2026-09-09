<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReversalFieldsToPaymentsTable extends Migration
{
    public function up()
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'reversed_at')) $table->timestamp('reversed_at')->nullable()->after('notes');
            if (!Schema::hasColumn('payments', 'reversed_by')) $table->unsignedBigInteger('reversed_by')->nullable()->after('reversed_at');
            if (!Schema::hasColumn('payments', 'reversal_reason')) $table->text('reversal_reason')->nullable()->after('reversed_by');
        });
        Schema::table('payments', function (Blueprint $table) { $table->index(['status', 'reversed_at'], 'payments_status_reversed_at_index'); });
    }

    public function down()
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_status_reversed_at_index');
            $columns = [];
            foreach (['reversed_at', 'reversed_by', 'reversal_reason'] as $column) if (Schema::hasColumn('payments', $column)) $columns[] = $column;
            if ($columns) $table->dropColumn($columns);
        });
    }
}
