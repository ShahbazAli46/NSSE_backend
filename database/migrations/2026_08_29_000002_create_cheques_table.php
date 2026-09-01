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
        Schema::create('cheques', function (Blueprint $table) {
            $table->id();
            $table->string('cheque_no')->unique();
            $table->decimal('amount', 14, 2);
            $table->unsignedTinyInteger('amount_tier')->default(1); // 1 = Green (<5k), 2 = Blue (5k-25k), 3 = Orange (25k-50k), 4 = Red (>50k)
            $table->string('tier_color', 20)->default('green');
            $table->date('issue_date');
            $table->date('cashed_date')->nullable(); // Date when money was cashed out from bank
            $table->enum('status', ['issued', 'cashed'])->default('issued');
            $table->string('beneficiary'); // "Self (Petty Cash Withdrawal)", "Vendor Name", "Staff Salaries"
            $table->enum('purpose', ['petty_cash', 'vendor_payment', 'salary', 'infrastructure', 'utility', 'other'])->default('other');
            $table->text('description')->nullable();
            $table->string('slip_url')->nullable(); // Photo of cheque counterfoil or bank clearance debit slip
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cheques');
    }
};
