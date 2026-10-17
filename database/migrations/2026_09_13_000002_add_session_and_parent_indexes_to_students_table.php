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
        Schema::table('students', function (Blueprint $table) {
            $table->string('session', 30)->default('2026-2027')->after('class')->index();

            // Drop old single registration_no unique index
            $table->dropUnique('students_registration_no_unique');

            // Add composite unique constraint per academic session
            $table->unique(['registration_no', 'session'], 'students_reg_no_session_unique');

            // Performance indexes for directory and Parent Portal lookups
            $table->index(['session', 'status'], 'students_session_status_idx');
            $table->index(['session', 'class'], 'students_session_class_idx');
            $table->index(['mobile_no', 'session'], 'students_mobile_session_idx');
            $table->index(['b_form_no', 'session'], 'students_bform_session_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex('students_session_status_idx');
            $table->dropIndex('students_session_class_idx');
            $table->dropIndex('students_mobile_session_idx');
            $table->dropIndex('students_bform_session_idx');
            $table->dropUnique('students_reg_no_session_unique');
            $table->dropColumn('session');

            $table->unique('registration_no', 'students_registration_no_unique');
        });
    }
};
