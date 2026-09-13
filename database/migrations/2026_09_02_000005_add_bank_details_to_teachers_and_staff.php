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
        Schema::table('teachers', function (Blueprint $table) {
            $table->string('payment_method', 20)->default('bank')->after('salary');
            $table->string('bank_name', 100)->nullable()->after('payment_method');
            $table->string('account_title', 150)->nullable()->after('bank_name');
            $table->string('account_number', 100)->nullable()->after('account_title');
        });

        Schema::table('staff_members', function (Blueprint $table) {
            $table->string('payment_method', 20)->default('bank')->after('salary');
            $table->string('bank_name', 100)->nullable()->after('payment_method');
            $table->string('account_title', 150)->nullable()->after('bank_name');
            $table->string('account_number', 100)->nullable()->after('account_title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'bank_name', 'account_title', 'account_number']);
        });

        Schema::table('staff_members', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'bank_name', 'account_title', 'account_number']);
        });
    }
};
