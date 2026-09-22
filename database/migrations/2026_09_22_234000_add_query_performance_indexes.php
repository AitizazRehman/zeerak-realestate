<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddQueryPerformanceIndexes extends Migration
{
    public function up()
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->index(['installment_plan_id', 'status'], 'installments_plan_status_index');
            $table->index(['status', 'due_date'], 'installments_status_due_date_index');
        });

        Schema::table('site_visits', function (Blueprint $table) {
            $table->index(['assigned_to', 'visit_at'], 'site_visits_assignee_visit_index');
            $table->index(['property_id', 'status'], 'site_visits_property_status_index');
            $table->index(['lead_id', 'status'], 'site_visits_lead_status_index');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->index(['assigned_to', 'next_follow_up'], 'leads_assignee_followup_index');
        });

        Schema::table('commissions', function (Blueprint $table) {
            $table->index(['booking_id', 'status'], 'commissions_booking_status_index');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->index(['property_id', 'expense_date'], 'expenses_property_date_index');
        });
    }

    public function down()
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->dropIndex('installments_plan_status_index');
            $table->dropIndex('installments_status_due_date_index');
        });
        Schema::table('site_visits', function (Blueprint $table) {
            $table->dropIndex('site_visits_assignee_visit_index');
            $table->dropIndex('site_visits_property_status_index');
            $table->dropIndex('site_visits_lead_status_index');
        });
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_assignee_followup_index');
        });
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropIndex('commissions_booking_status_index');
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('expenses_property_date_index');
        });
    }
}
