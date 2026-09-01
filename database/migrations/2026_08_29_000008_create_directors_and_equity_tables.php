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
        // 1. Directors / Equity Partners
        Schema::create('directors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('cnic')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->decimal('share_percentage', 5, 2)->default(0.00); // e.g. 40.00%
            $table->string('bank_name')->nullable();
            $table->string('bank_account_no')->nullable();
            $table->string('bank_iban')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        // 2. Monthly Profit Distribution Batches
        Schema::create('profit_distributions', function (Blueprint $table) {
            $table->id();
            $table->string('period_month', 7); // e.g. "2026-08"
            $table->decimal('total_profit_pool', 14, 2);
            $table->text('notes')->nullable();
            $table->foreignId('distributed_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index('period_month');
        });

        // 3. Director Itemized Transactions (Capital Injections, Profit Allocations, Withdrawals)
        Schema::create('director_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('director_id')->constrained('directors')->cascadeOnDelete();
            $table->enum('type', ['capital_injection', 'profit_credit', 'withdrawal']);
            $table->decimal('amount', 14, 2);
            $table->date('date');
            $table->string('payment_method')->default('Bank Transfer'); // Bank Transfer, Cheque, Cash, Profit Share Allocation
            $table->string('reference_no')->nullable();
            $table->foreignId('cheque_id')->nullable()->constrained('cheques')->nullOnDelete();
            $table->string('period_month', 7)->nullable(); // e.g. "2026-08"
            $table->foreignId('profit_distribution_id')->nullable()->constrained('profit_distributions')->nullOnDelete();
            $table->string('slip_url')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['director_id', 'date']);
            $table->index(['type', 'date']);
            $table->index('period_month');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('director_transactions');
        Schema::dropIfExists('profit_distributions');
        Schema::dropIfExists('directors');
    }
};
