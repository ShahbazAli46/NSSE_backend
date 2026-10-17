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
        Schema::table('student_attendances', function (Blueprint $table) {
            $table->string('follow_up_status', 30)->default('pending')->after('remarks');
            $table->string('follow_up_reason', 100)->nullable()->after('follow_up_status');
            $table->text('follow_up_notes')->nullable()->after('follow_up_reason');
            $table->foreignId('followed_up_by')->nullable()->after('follow_up_notes')->constrained('users')->nullOnDelete();
            $table->timestamp('followed_up_at')->nullable()->after('followed_up_by');

            $table->index(['date', 'follow_up_status'], 'std_att_date_follow_up_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_attendances', function (Blueprint $table) {
            $table->dropIndex('std_att_date_follow_up_idx');
            $table->dropForeign(['followed_up_by']);
            $table->dropColumn([
                'follow_up_status',
                'follow_up_reason',
                'follow_up_notes',
                'followed_up_by',
                'followed_up_at',
            ]);
        });
    }
};
