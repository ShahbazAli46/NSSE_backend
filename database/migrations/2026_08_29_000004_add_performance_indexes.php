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
        Schema::table('grants', function (Blueprint $table) {
            $table->index('deposit_date');
            $table->index('created_at');
        });

        Schema::table('cheques', function (Blueprint $table) {
            $table->index('status');
            $table->index('cashed_date');
            $table->index('issue_date');
            $table->index(['status', 'cashed_date']);
            $table->index('purpose');
            $table->index('amount_tier');
            $table->index('created_at');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->index('amount_tier');
            $table->index('payment_method');
            $table->index(['amount_tier', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grants', function (Blueprint $table) {
            $table->dropIndex(['deposit_date']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('cheques', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['cashed_date']);
            $table->dropIndex(['issue_date']);
            $table->dropIndex(['status', 'cashed_date']);
            $table->dropIndex(['purpose']);
            $table->dropIndex(['amount_tier']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['amount_tier']);
            $table->dropIndex(['payment_method']);
            $table->dropIndex(['amount_tier', 'created_at']);
        });
    }
};
