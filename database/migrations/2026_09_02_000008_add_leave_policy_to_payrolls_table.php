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
            $table->integer('allowed_leaves')->default(2)->after('leave_days');
            $table->decimal('deductible_days', 5, 2)->default(0)->after('allowed_leaves');
            $table->decimal('daily_rate', 12, 2)->default(0)->after('deductible_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn(['allowed_leaves', 'deductible_days', 'daily_rate']);
        });
    }
};
