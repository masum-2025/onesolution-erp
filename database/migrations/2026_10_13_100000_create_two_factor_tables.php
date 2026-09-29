<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-step sign-in (Phase 8-1). All of it belongs to the person (one
 * identity), not to an organization, except reset requests.
 *
 * - users.mfa_enabled_at: set while the person has at least one second
 *   step (an authenticator app or a passkey).
 * - users.mfa_required_since: the first time an organization they work in
 *   required it; the grace period (rule identity.mfa_grace_days) counts from here.
 * - user_totp: the authenticator app secret (encrypted) and the last time
 *   step used, so a code is never accepted twice.
 * - user_recovery_codes: ten single-use codes, stored hashed only.
 * - user_passkeys: WebAuthn credentials, per address (rp_id), so a
 *   partner's own domain keeps its own passkeys.
 * - mfa_resets: an admin asks to clear a member's second steps (lost
 *   phone); another admin approves (maker-checker).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('mfa_enabled_at')->nullable();
            $table->timestamp('mfa_required_since')->nullable();
        });

        Schema::create('user_totp', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('secret');
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('last_used_step')->nullable();
            $table->timestamps();
        });

        Schema::create('user_recovery_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code_hash', 64);
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'used_at']);
        });

        Schema::create('user_passkeys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('rp_id', 253);
            // base64url of the raw credential id; unique per address through its
            // SHA-256 (a credential id may be up to 1023 bytes, too long to index).
            $table->text('credential_id');
            $table->char('credential_hash', 64);
            $table->text('public_key');
            $table->json('transports')->nullable();
            $table->string('aaguid', 36)->nullable();
            $table->unsignedBigInteger('counter')->default(0);
            $table->boolean('backup_eligible')->nullable();
            $table->boolean('backup_status')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'rp_id']);
            $table->unique(['rp_id', 'credential_hash']);
        });

        Schema::create('mfa_resets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('requested_by')->constrained('users');
            $table->string('reason', 500);
            $table->string('status', 20)->default('pending');
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_resets');
        Schema::dropIfExists('user_passkeys');
        Schema::dropIfExists('user_recovery_codes');
        Schema::dropIfExists('user_totp');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_enabled_at', 'mfa_required_since']);
        });
    }
};
