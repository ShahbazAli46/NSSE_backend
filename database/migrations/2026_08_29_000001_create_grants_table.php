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
        Schema::create('grants', function (Blueprint $table) {
            $table->id();
            $table->string('title'); // e.g. "1st Quarter Operational Grant 2026-27"
            $table->decimal('amount', 14, 2);
            $table->date('deposit_date');
            $table->string('bank_account')->default('Main School Account');
            $table->string('reference_no')->nullable(); // Bank deposit slip / transaction ID
            $table->string('slip_url')->nullable(); // Photo of deposit slip / bank statement
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grants');
    }
};
