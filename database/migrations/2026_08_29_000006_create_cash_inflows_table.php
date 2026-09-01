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
        Schema::create('cash_inflows', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->decimal('amount', 14, 2);
            $table->date('deposit_date');
            $table->string('category')->default('Miscellaneous Inflow'); // Opening Balance, Donation, Scrap Sale, Fee Collection, Other
            $table->string('reference_no')->nullable();
            $table->string('slip_url')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['deposit_date', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_inflows');
    }
};
