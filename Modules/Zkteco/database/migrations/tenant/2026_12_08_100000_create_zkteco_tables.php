<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ZKT-1: ZKTeco terminals.
 *
 * - zk_devices: a terminal on the office network (private IP, port, UDP or
 *   TCP, comm key kept encrypted) at a branch; what it said about itself
 *   (serial, model, how many users and logs); its logs are brought in from
 *   import_from on; the last try, the last success and the last error.
 * - zk_device_users: the terminal's users (its user id and name) and the
 *   HRM employee each is: matched by employee code, or by hand.
 * - zk_logs: every log read from a terminal since import_from, once
 *   (device, user id, the terminal's own local time); its state: recorded
 *   into attendance, unmatched (no employee yet), or not_employed that day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zk_devices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->string('name', 80);
            $table->string('host', 45);
            $table->unsignedInteger('port')->default(4370);
            $table->string('protocol', 3)->default('udp');
            $table->text('comm_key')->nullable();
            $table->date('import_from');
            $table->boolean('is_active')->default(true);
            $table->string('serial', 40)->nullable();
            $table->string('model', 60)->nullable();
            $table->unsignedInteger('users_count')->nullable();
            $table->unsignedInteger('logs_count')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 200)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'host', 'port'], 'zk_devices_address_unique');
        });

        Schema::create('zk_device_users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('device_id');
            $table->unsignedInteger('device_uid')->nullable();
            $table->string('user_id', 24);
            $table->string('name', 40)->nullable();
            $table->ulid('employee_id')->nullable();
            // "code" (the employee code is the user id) or "hand".
            $table->string('matched_by', 4)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'device_id', 'user_id'], 'zk_device_users_unique');
        });

        Schema::create('zk_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('device_id');
            $table->string('user_id', 24);
            // As the terminal's clock wrote it, and the instant it is (UTC).
            $table->dateTime('local_at');
            $table->timestamp('punched_at');
            $table->unsignedSmallInteger('status')->default(0);
            $table->unsignedSmallInteger('punch')->default(0);
            $table->ulid('employee_id')->nullable();
            $table->string('state', 12)->default('unmatched');
            $table->timestamps();

            $table->unique(['organization_id', 'device_id', 'user_id', 'local_at'], 'zk_logs_once_unique');
            $table->index(['organization_id', 'device_id', 'state'], 'zk_logs_state_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zk_logs');
        Schema::dropIfExists('zk_device_users');
        Schema::dropIfExists('zk_devices');
    }
};
