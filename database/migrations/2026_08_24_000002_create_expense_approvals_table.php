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
        Schema::create('expense_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained('expenses')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('decision', ['approved', 'rejected']);
            $table->text('remarks')->nullable();
            $table->timestamp('voted_at')->useCurrent();
            $table->timestamps();

            // Strict Concurrency & Idempotency Guarantee
            $table->unique(['expense_id', 'user_id'], 'unique_expense_user_vote');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_approvals');
    }
};
