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
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->index('start_date', 'idx_leave_start_date');
            $table->index('end_date', 'idx_leave_end_date');
            $table->index('created_at', 'idx_leave_created_at');
            $table->index(['start_date', 'end_date'], 'idx_leave_date_span');
            $table->index(['user_id', 'created_at'], 'idx_leave_user_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropIndex('idx_leave_start_date');
            $table->dropIndex('idx_leave_end_date');
            $table->dropIndex('idx_leave_created_at');
            $table->dropIndex('idx_leave_date_span');
            $table->dropIndex('idx_leave_user_created');
        });
    }
};
