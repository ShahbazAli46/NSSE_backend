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
        Schema::table('director_transactions', function (Blueprint $table) {
            $table->index(['director_id', 'type', 'date'], 'idx_dt_dir_type_date');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->index(['director_id', 'expense_type', 'created_at'], 'idx_exp_dir_type_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('idx_exp_dir_type_created');
        });

        Schema::table('director_transactions', function (Blueprint $table) {
            $table->dropIndex('idx_dt_dir_type_date');
        });
    }
};
