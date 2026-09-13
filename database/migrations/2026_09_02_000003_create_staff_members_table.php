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
        Schema::create('staff_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('designation'); // e.g. Gatekeeper, Security Guard, Office Boy, Peon, Driver, Electrician, Janitor
            $table->string('father_name')->nullable();
            $table->string('cnic')->nullable();
            $table->string('contact_number')->nullable();
            $table->string('emergency_number')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->date('date_of_joining')->nullable();
            $table->decimal('salary', 12, 2)->default(0);
            $table->json('allowances')->nullable();
            $table->string('status', 30)->default('active'); // active, on_leave, inactive
            $table->string('profile_picture')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('designation');
            $table->index('cnic');
            $table->index('contact_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_members');
    }
};
