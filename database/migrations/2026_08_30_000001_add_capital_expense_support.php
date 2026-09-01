<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add expense_type and director_id to expenses
        Schema::table('expenses', function (Blueprint $table) {
            if (!Schema::hasColumn('expenses', 'expense_type')) {
                $table->string('expense_type', 20)->default('general')->after('amount'); // 'general', 'capital'
            }
            if (!Schema::hasColumn('expenses', 'director_id')) {
                $table->foreignId('director_id')->nullable()->after('expense_type')->constrained('directors')->nullOnDelete();
            }
        });

        // 2. Add expense_id and expand type on director_transactions
        Schema::table('director_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('director_transactions', 'expense_id')) {
                $table->foreignId('expense_id')->nullable()->after('profit_distribution_id')->constrained('expenses')->nullOnDelete();
            }
        });

        // Update enum / column type for director_transactions.type in SQLite / MySQL
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE director_transactions MODIFY COLUMN type ENUM('capital_injection', 'capital_expense', 'profit_credit', 'withdrawal') NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('director_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('director_transactions', 'expense_id')) {
                $table->dropForeign(['expense_id']);
                $table->dropColumn('expense_id');
            }
        });

        Schema::table('expenses', function (Blueprint $table) {
            if (Schema::hasColumn('expenses', 'director_id')) {
                $table->dropForeign(['director_id']);
                $table->dropColumn('director_id');
            }
            if (Schema::hasColumn('expenses', 'expense_type')) {
                $table->dropColumn('expense_type');
            }
        });
    }
};
