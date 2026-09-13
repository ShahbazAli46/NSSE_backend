<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Optimize payrolls table for high-volume historical querying (3-4+ years)
        Schema::table('payrolls', function (Blueprint $table) {
            $table->index('month', 'payrolls_month_idx');
            $table->index('status', 'payrolls_status_idx');
            $table->index(['month', 'status'], 'payrolls_month_status_idx');
            $table->index(['staff_type', 'staff_id', 'month'], 'payrolls_staff_history_idx');
            $table->index(['total_bonuses', 'month'], 'payrolls_bonuses_month_idx');
        });

        // 2. Optimize staff_attendances table for multi-year range & status aggregations
        Schema::table('staff_attendances', function (Blueprint $table) {
            $table->index(['date', 'staff_type', 'status'], 'staff_att_date_type_status_idx');
        });

        // 3. Optimize teachers and staff_members tables for active alphabetical rosters
        Schema::table('teachers', function (Blueprint $table) {
            $table->index(['status', 'name'], 'teachers_status_name_idx');
        });

        Schema::table('staff_members', function (Blueprint $table) {
            $table->index(['status', 'name'], 'staff_members_status_name_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropIndex('payrolls_month_idx');
            $table->dropIndex('payrolls_status_idx');
            $table->dropIndex('payrolls_month_status_idx');
            $table->dropIndex('payrolls_staff_history_idx');
            $table->dropIndex('payrolls_bonuses_month_idx');
        });

        Schema::table('staff_attendances', function (Blueprint $table) {
            $table->dropIndex('staff_att_date_type_status_idx');
        });

        Schema::table('teachers', function (Blueprint $table) {
            $table->dropIndex('teachers_status_name_idx');
        });

        Schema::table('staff_members', function (Blueprint $table) {
            $table->dropIndex('staff_members_status_name_idx');
        });
    }
};
