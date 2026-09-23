<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddBranchOwnershipToCustomersAndExpenses extends Migration
{
    public function up()
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->after('customer_number');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('set null');
            $table->index(['branch_id', 'is_active'], 'customers_branch_active_index');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->after('id');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('set null');
            $table->index(['branch_id', 'expense_date'], 'expenses_branch_date_index');
        });

        DB::table('customers')->orderBy('id')->chunkById(200, function ($customers) {
            foreach ($customers as $customer) {
                $branchId = DB::table('bookings')
                    ->join('properties', 'properties.id', '=', 'bookings.property_id')
                    ->join('projects', 'projects.id', '=', 'properties.project_id')
                    ->where('bookings.customer_id', $customer->id)
                    ->whereNull('bookings.deleted_at')
                    ->value('projects.branch_id');

                if (!$branchId) {
                    $branchId = DB::table('leads')
                        ->join('projects', 'projects.id', '=', 'leads.project_id')
                        ->where('leads.customer_id', $customer->id)
                        ->whereNull('leads.deleted_at')
                        ->value('projects.branch_id');
                }

                if (!$branchId) {
                    $branchId = DB::table('leads')
                        ->join('users', 'users.id', '=', 'leads.assigned_to')
                        ->where('leads.customer_id', $customer->id)
                        ->whereNull('leads.deleted_at')
                        ->value('users.branch_id');
                }

                if ($branchId) {
                    DB::table('customers')->where('id', $customer->id)->update(['branch_id' => $branchId]);
                }
            }
        }, 'id');

        DB::table('expenses')->orderBy('id')->chunkById(200, function ($expenses) {
            foreach ($expenses as $expense) {
                $branchId = null;

                if ($expense->project_id) {
                    $branchId = DB::table('projects')->where('id', $expense->project_id)->value('branch_id');
                }

                if (!$branchId && $expense->property_id) {
                    $branchId = DB::table('properties')
                        ->join('projects', 'projects.id', '=', 'properties.project_id')
                        ->where('properties.id', $expense->property_id)
                        ->value('projects.branch_id');
                }

                if (!$branchId && $expense->created_by) {
                    $branchId = DB::table('users')->where('id', $expense->created_by)->value('branch_id');
                }

                if ($branchId) {
                    DB::table('expenses')->where('id', $expense->id)->update(['branch_id' => $branchId]);
                }
            }
        }, 'id');
    }

    public function down()
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropIndex('expenses_branch_date_index');
            $table->dropColumn('branch_id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropIndex('customers_branch_active_index');
            $table->dropColumn('branch_id');
        });
    }
}
