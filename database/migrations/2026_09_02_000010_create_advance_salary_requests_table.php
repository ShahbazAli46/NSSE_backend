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
        Schema::create('advance_salary_requests', function (Blueprint $table) {
            $table->id();
            $table->string('staff_type', 30)->default('teacher'); // 'teacher' or 'non_teaching'
            $table->unsignedBigInteger('staff_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('staff_name');
            $table->string('designation');
            $table->decimal('amount', 12, 2);
            $table->text('reason');
            $table->string('repayment_plan')->nullable(); // e.g. "Deduct from next month", "2 Monthly installments"
            
            // Approval Workflow State
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reviewed_by_name')->nullable();
            $table->string('reviewed_by_role')->nullable(); // 'principal', 'director', 'chairman', 'admin'
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();
            $table->text('rejection_reason')->nullable();
            
            // Financial Recovery & Disbursement Lifecycle
            $table->string('disbursement_status', 30)->default('pending'); // 'pending', 'disbursed', 'deducted_in_full'
            $table->decimal('total_recovered', 12, 2)->default(0);
            $table->decimal('remaining_balance', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['staff_type', 'staff_id'], 'adv_salary_staff_idx');
            $table->index('status', 'adv_salary_status_idx');
            $table->index('created_at', 'adv_salary_created_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('advance_salary_requests');
    }
};
