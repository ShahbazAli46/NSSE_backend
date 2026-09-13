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
        Schema::create('staff_attendances', function (Blueprint $table) {
            $table->id();
            $table->string('staff_type', 30); // 'teacher' or 'non_teaching'
            $table->unsignedBigInteger('staff_id'); // teacher_id or staff_member_id
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('date');
            $table->string('status', 30)->default('present'); // 'present', 'late', 'absent', 'on_leave'
            $table->string('check_in_time', 20)->nullable(); // e.g. "07:45 AM"
            $table->string('check_out_time', 20)->nullable(); // e.g. "02:00 PM"
            $table->text('remarks')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['staff_type', 'staff_id', 'date'], 'staff_attendance_unique_day');
            $table->index(['date', 'status']);
            $table->index(['staff_type', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_attendances');
    }
};
