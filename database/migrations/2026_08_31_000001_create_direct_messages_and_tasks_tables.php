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
        Schema::create('direct_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->text('message')->nullable();
            $table->string('type')->default('text'); // text, voice, image, document
            $table->string('attachment_url')->nullable();
            $table->string('attachment_name')->nullable();
            $table->integer('duration')->nullable(); // Voice duration in seconds
            $table->boolean('is_task')->default(false);
            $table->unsignedBigInteger('task_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['sender_id', 'recipient_id', 'created_at'], 'idx_dm_sender_recipient_created');
            $table->index(['recipient_id', 'read_at'], 'idx_dm_recipient_read');
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assignee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('direct_messages')->nullOnDelete();
            $table->text('title');
            $table->string('priority')->default('normal'); // normal, urgent
            $table->date('due_date')->nullable();
            $table->string('status')->default('pending'); // pending, in_progress, completed
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['assignee_id', 'status'], 'idx_tasks_assignee_status');
            $table->index(['creator_id', 'status'], 'idx_tasks_creator_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('direct_messages');
    }
};
