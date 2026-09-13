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
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->string('month', 7); // Format: YYYY-MM
            $table->enum('staff_type', ['teacher', 'non_teaching']);
            $table->unsignedBigInteger('staff_id');
            $table->string('staff_name');
            $table->string('designation');
            $table->decimal('base_salary', 12, 2)->default(0);
            $table->json('allowances')->nullable();
            $table->decimal('total_allowances', 12, 2)->default(0);
            $table->decimal('gross_salary', 12, 2)->default(0);
            
            // Attendance Summary for that Month
            $table->integer('total_working_days')->default(26);
            $table->integer('present_days')->default(0);
            $table->integer('late_days')->default(0);
            $table->integer('absent_days')->default(0);
            $table->integer('leave_days')->default(0);
            
            // Deductions & Net Pay
            $table->decimal('absent_deduction', 12, 2)->default(0);
            $table->decimal('other_deductions', 12, 2)->default(0);
            $table->decimal('net_salary', 12, 2)->default(0);
            
            // Payment Details
            $table->string('payment_method', 20)->default('bank'); // bank, cash
            $table->string('bank_name', 100)->nullable();
            $table->string('account_title', 150)->nullable();
            $table->string('account_number', 100)->nullable();
            
            // Status
            $table->enum('status', ['generated', 'paid'])->default('generated');
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['month', 'staff_type', 'staff_id'], 'payroll_month_staff_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
