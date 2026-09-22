<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddBranchIdToFinancialAuditsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('financial_audits', 'branch_id')) {
            Schema::table('financial_audits', function (Blueprint $table) {
                $table->unsignedBigInteger('branch_id')->nullable()->after('entity_id')->index();
            });
        }

        DB::table('financial_audits')->whereNull('branch_id')->orderBy('id')->chunkById(200, function ($audits) {
            foreach ($audits as $audit) {
                $branchId = null;
                if ($audit->entity_type === 'payment') {
                    $branchId = DB::table('payments')->join('bookings','bookings.id','=','payments.booking_id')->join('properties','properties.id','=','bookings.property_id')->join('projects','projects.id','=','properties.project_id')->where('payments.id',$audit->entity_id)->value('projects.branch_id');
                } elseif ($audit->entity_type === 'booking') {
                    $branchId = DB::table('bookings')->join('properties','properties.id','=','bookings.property_id')->join('projects','projects.id','=','properties.project_id')->where('bookings.id',$audit->entity_id)->value('projects.branch_id');
                } elseif ($audit->entity_type === 'expense') {
                    $expense = DB::table('expenses')->where('id',$audit->entity_id)->first();
                    if ($expense) {
                        if ($expense->project_id) $branchId = DB::table('projects')->where('id',$expense->project_id)->value('branch_id');
                        elseif ($expense->property_id) $branchId = DB::table('properties')->join('projects','projects.id','=','properties.project_id')->where('properties.id',$expense->property_id)->value('projects.branch_id');
                    }
                } elseif ($audit->entity_type === 'installment_plan') {
                    $branchId = DB::table('installment_plans')->join('bookings','bookings.id','=','installment_plans.booking_id')->join('properties','properties.id','=','bookings.property_id')->join('projects','projects.id','=','properties.project_id')->where('installment_plans.id',$audit->entity_id)->value('projects.branch_id');
                } elseif ($audit->entity_type === 'commission') {
                    $branchId = DB::table('commissions')->join('bookings','bookings.id','=','commissions.booking_id')->join('properties','properties.id','=','bookings.property_id')->join('projects','projects.id','=','properties.project_id')->where('commissions.id',$audit->entity_id)->value('projects.branch_id');
                }
                if ($branchId) DB::table('financial_audits')->where('id',$audit->id)->update(['branch_id'=>$branchId]);
            }
        });
    }

    public function down()
    {
        if (Schema::hasColumn('financial_audits', 'branch_id')) {
            Schema::table('financial_audits', function (Blueprint $table) {
                $table->dropColumn('branch_id');
            });
        }
    }
}
