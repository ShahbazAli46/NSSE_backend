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
        Schema::table('payrolls', function (Blueprint $table) {
            $table->json('bonuses')->nullable()->after('gross_salary');
            $table->decimal('total_bonuses', 12, 2)->default(0)->after('bonuses');
            $table->decimal('advance_deduction', 12, 2)->default(0)->after('absent_deduction');
            $table->json('custom_deductions')->nullable()->after('advance_deduction');
            $table->text('remarks')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn(['bonuses', 'total_bonuses', 'advance_deduction', 'custom_deductions', 'remarks']);
        });
    }
};
