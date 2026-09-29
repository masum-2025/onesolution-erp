<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline mode and secure sync (Phase 7).
 *
 * - devices: a browser or app a person uses offline for one organization.
 *   Revoking one makes it wipe its local data on its next contact.
 * - offline_leases: what a device may do offline, signed by the server
 *   (user, organization, permissions, rule snapshot, until when). Kept so an
 *   operation can be checked against the lease it was made under.
 * - sync_operations: every operation a device sent, once per (device, op_id):
 *   a repeat gets the stored result back and is never applied twice.
 * - sync_quarantine: operations from a device or person no longer allowed
 *   (revoked device, membership ended, permission gone), held for an admin
 *   to release or discard. The payload is encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->string('name', 80);
            $table->string('platform', 60)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUlid('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 500)->nullable();
            // Set by a revoke or the module going off; cleared never. wiped_at: the device confirmed.
            $table->timestamp('wipe_requested_at')->nullable();
            $table->timestamp('wiped_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
            $table->index(['organization_id', 'revoked_at']);
        });

        Schema::create('offline_leases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->string('rule_version', 200);
            // The rules the device got, and a fingerprint of the sensitive ones among them.
            $table->json('rule_keys');
            $table->string('sensitive_hash', 64);
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();

            $table->index(['device_id', 'expires_at']);
        });

        Schema::create('sync_operations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('op_id', 64);
            $table->string('kind', 60);
            // create | update | delete
            $table->string('action', 10);
            $table->string('record_id', 26)->nullable();
            // applied | conflict | rejected | quarantined
            $table->string('status', 20);
            $table->json('result');
            $table->timestamp('made_at')->nullable();
            $table->timestamp('received_at');

            $table->unique(['device_id', 'op_id']);
            $table->index(['organization_id', 'received_at']);
        });

        Schema::create('sync_quarantine', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('op_id', 64);
            $table->string('kind', 60);
            $table->string('action', 10);
            $table->boolean('money')->default(false);
            // The whole operation, encrypted.
            $table->text('payload');
            // device_revoked | membership_ended | permission_missing
            $table->string('reason', 30);
            // pending → released | discarded
            $table->string('status', 20);
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->json('decision_result')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['device_id', 'op_id']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_quarantine');
        Schema::dropIfExists('sync_operations');
        Schema::dropIfExists('offline_leases');
        Schema::dropIfExists('devices');
    }
};
