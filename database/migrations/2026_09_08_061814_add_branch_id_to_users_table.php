<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBranchIdToUsersTable extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {

            $table->foreignId('branch_id')
                ->nullable()
                ->after('id')
                ->constrained('branches')
                ->nullOnDelete();

            $table->index('branch_id');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {

            $table->dropForeign([
                'branch_id'
            ]);

            $table->dropColumn('branch_id');
        });
    }
}