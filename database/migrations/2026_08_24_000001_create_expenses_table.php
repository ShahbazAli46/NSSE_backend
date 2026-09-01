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
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('voucher_no')->unique();
            $table->string('title');
            $table->string('category'); // Utilities, Salaries, Maintenance, Events, Stationery, Construction, Other
            $table->decimal('amount', 12, 2); // Max up to 999,999,999.99 PKR
            $table->text('description');
            $table->string('receipt_url')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->enum('status', ['pending', 'clarification_requested', 'approved', 'rejected', 'disbursed'])->default('pending');
            $table->timestamp('disbursed_at')->nullable();
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_method')->nullable(); // Cash, Bank Transfer, Cheque
            $table->string('payment_reference')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
