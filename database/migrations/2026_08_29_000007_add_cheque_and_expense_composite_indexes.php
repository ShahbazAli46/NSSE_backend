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
        Schema::table('cheques', function (Blueprint $table) {
            $table->index(['status', 'purpose', 'cashed_date'], 'cheques_status_purpose_cashed_idx');
            $table->index(['created_by', 'created_at'], 'cheques_created_by_at_idx');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->index(['payment_method', 'created_at'], 'expenses_payment_method_created_idx');
        });

        Schema::table('grants', function (Blueprint $table) {
            $table->index(['deposit_date', 'created_at'], 'grants_deposit_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->dropIndex('cheques_status_purpose_cashed_idx');
            $table->dropIndex('cheques_created_by_at_idx');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('expenses_payment_method_created_idx');
        });

        Schema::table('grants', function (Blueprint $table) {
            $table->dropIndex('grants_deposit_created_idx');
        });
    }
};
