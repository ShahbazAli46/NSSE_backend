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
        Schema::create('student_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->date('date');
            $table->string('session', 50)->default('2026-2027');
            $table->string('class', 100);
            $table->string('section', 50)->default('A');
            $table->enum('status', ['present', 'absent', 'late', 'leave'])->default('present');
            $table->string('teacher_name', 150)->nullable();
            $table->foreignId('teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
            $table->string('remarks', 255)->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('marked_at')->nullable();
            $table->timestamps();

            // Core Constraints & Composite Indexes
            $table->unique(['student_id', 'date'], 'student_daily_attendance_unique');
            $table->index(['date', 'session', 'class', 'section'], 'std_att_class_sheet_idx');
            $table->index(['date', 'status'], 'std_att_daily_status_idx');
            $table->index(['student_id', 'date'], 'std_att_student_history_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_attendances');
    }
};
