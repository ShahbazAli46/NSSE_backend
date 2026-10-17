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
            $table->string('whatsapp_status', 30)->default('pending')->after('followed_up_at');
            $table->string('whatsapp_msg_id', 100)->nullable()->after('whatsapp_status');
            $table->timestamp('whatsapp_sent_at')->nullable()->after('whatsapp_msg_id');
            $table->string('whatsapp_error', 255)->nullable()->after('whatsapp_sent_at');

            $table->index(['date', 'whatsapp_status'], 'std_att_date_whatsapp_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_attendances', function (Blueprint $table) {
            $table->dropIndex('std_att_date_whatsapp_idx');
            $table->dropColumn([
                'whatsapp_status',
                'whatsapp_msg_id',
                'whatsapp_sent_at',
                'whatsapp_error',
            ]);
        });
    }
};
