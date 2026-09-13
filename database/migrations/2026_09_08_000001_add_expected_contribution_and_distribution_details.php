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
        Schema::table('directors', function (Blueprint $table) {
            if (!Schema::hasColumn('directors', 'expected_monthly_contribution')) {
                $table->decimal('expected_monthly_contribution', 14, 2)->default(0.00)->after('share_percentage');
            }
        });

        Schema::table('profit_distributions', function (Blueprint $table) {
            if (!Schema::hasColumn('profit_distributions', 'distribution_breakdown')) {
                $table->json('distribution_breakdown')->nullable()->after('notes');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('profit_distributions', function (Blueprint $table) {
            if (Schema::hasColumn('profit_distributions', 'distribution_breakdown')) {
                $table->dropColumn('distribution_breakdown');
            }
        });

        Schema::table('directors', function (Blueprint $table) {
            if (Schema::hasColumn('directors', 'expected_monthly_contribution')) {
                $table->dropColumn('expected_monthly_contribution');
            }
        });
    }
};
