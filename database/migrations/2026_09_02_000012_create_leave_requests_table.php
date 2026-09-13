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
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('staff_type', ['teacher', 'non_teaching'])->default('teacher');
            $table->unsignedBigInteger('staff_id');
            $table->string('staff_name');
            $table->string('designation')->nullable();
            
            $table->enum('leave_type', ['casual', 'sick', 'emergency', 'annual', 'maternity', 'other'])->default('casual');
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedInteger('total_days')->default(1);
            $table->text('reason');

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approver_name')->nullable();
            $table->text('approver_remarks')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'start_date', 'end_date'], 'idx_leave_status_dates');
            $table->index(['staff_type', 'staff_id'], 'idx_leave_staff');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
