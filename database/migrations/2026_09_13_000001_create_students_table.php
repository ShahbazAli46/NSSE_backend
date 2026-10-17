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
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('registration_no')->unique()->index();
            $table->string('student_name')->index();
            $table->string('father_name')->nullable()->index();
            $table->string('b_form_no')->nullable()->index();
            $table->string('class')->index();
            $table->string('section')->default('A');
            $table->string('gender', 20)->nullable();
            $table->date('admission_date')->nullable();
            $table->string('mobile_no', 50)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['class', 'section', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
