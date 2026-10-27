<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raw ZKTeco ADMS punch intake + device <-> student mapping column.
     */
    public function up(): void
    {
        Schema::create('bio_punches', function (Blueprint $table) {
            $table->id();
            $table->string('bio_user_id', 50)->index();          // Device enrollment ID (raw, unmapped)
            $table->dateTime('punch_time');
            $table->tinyInteger('punch_type')->default(0);       // Device verification result column
            $table->string('verify_mode', 20)->nullable();
            $table->string('work_code', 20)->nullable();
            $table->string('machine_sn', 50)->nullable()->index();
            $table->timestamp('processed_at')->nullable()->index(); // Filled once mapped into student_attendances
            $table->string('process_note', 255)->nullable();     // Why a punch could not be mapped (e.g. unknown ID)
            $table->timestamp('created_at')->useCurrent();

            // Deduplication barrier: identical device punch never stored twice,
            // INSERT IGNORE silently drops duplicate re-transmission dumps.
            $table->unique(['bio_user_id', 'punch_time'], 'bio_punch_unique');
        });

        Schema::table('students', function (Blueprint $table) {
            // Optional explicit mapping; when null the receiver falls back to
            // matching the device enrollment ID against `registration_no`.
            $table->string('bio_user_id', 50)->nullable()->index('students_bio_user_id_idx')->after('section');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bio_punches');
    }
};
