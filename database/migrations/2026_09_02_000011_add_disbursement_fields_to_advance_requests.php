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
        Schema::table('advance_salary_requests', function (Blueprint $table) {
            $table->foreignId('disbursed_by')->nullable()->after('disbursement_status')->constrained('users')->nullOnDelete();
            $table->string('disbursed_by_name')->nullable()->after('disbursed_by');
            $table->timestamp('disbursed_at')->nullable()->after('disbursed_by_name');
            $table->string('payment_source', 30)->nullable()->after('disbursed_at'); // 'bank' or 'cash'
            $table->string('payment_reference')->nullable()->after('payment_source');
            $table->text('disbursement_notes')->nullable()->after('payment_reference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('advance_salary_requests', function (Blueprint $table) {
            $table->dropForeign(['disbursed_by']);
            $table->dropColumn([
                'disbursed_by',
                'disbursed_by_name',
                'disbursed_at',
                'payment_source',
                'payment_reference',
                'disbursement_notes',
            ]);
        });
    }
};
