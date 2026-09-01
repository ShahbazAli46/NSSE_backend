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
        Schema::table('expenses', function (Blueprint $table) {
            $table->unsignedTinyInteger('amount_tier')->default(1)->after('amount'); // 1=Green (<5k), 2=Blue (5k-25k), 3=Orange (25k-50k), 4=Red (>50k)
            $table->string('tier_color', 20)->default('green')->after('amount_tier');
            $table->foreignId('cheque_id')->nullable()->after('payment_reference')->constrained('cheques')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['cheque_id']);
            $table->dropColumn(['amount_tier', 'tier_color', 'cheque_id']);
        });
    }
};
