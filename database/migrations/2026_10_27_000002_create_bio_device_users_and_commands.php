<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-machine enrolled user registry + outbound command queue for the
     * ZKTeco ADMS push channel.
     */
    public function up(): void
    {
        Schema::create('bio_device_users', function (Blueprint $table) {
            $table->id();
            $table->string('machine_sn', 50)->index();
            $table->string('device_user_id', 50)->index();       // PIN enrolled on the device
            $table->string('device_name', 100)->nullable();      // Name stored in the device record
            $table->string('pending_name', 100)->nullable();     // Name queued for sync to device
            $table->string('name_sync_status', 20)->default('in_sync'); // in_sync | pending | synced | failed
            $table->string('card_no', 50)->nullable();
            $table->tinyInteger('privilege')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['machine_sn', 'device_user_id'], 'bio_device_user_unique');
        });

        Schema::create('bio_device_commands', function (Blueprint $table) {
            $table->id();
            $table->string('machine_sn', 50)->index();
            $table->text('command');                             // Raw protocol command (payload after "C:id:")
            $table->string('status', 20)->default('queued')->index(); // queued | sent | acked_ok | acked_err | failed
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acked_at')->nullable();
            $table->text('result')->nullable();                  // Device reply body
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bio_device_commands');
        Schema::dropIfExists('bio_device_users');
    }
};
