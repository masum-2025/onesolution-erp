<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-serve identity (Phase 5C-1).
 *
 * - users: a verified phone (E.164) as a second way to sign in, email becomes
 *   optional (phone-only people), the person's language, marketing consent,
 *   and the times that guard recovery (password changed, recovered).
 * - otp_challenges: one-time codes (only a hash) for sign-up, recovery and
 *   verifying a new email or phone. The destination is kept as a hash for
 *   limits; pending sign-up details are encrypted.
 * - user_sessions: the person's signed-in browsers and devices, so they can
 *   see and end them whatever the session store is.
 * - plans.audience: business plans for organizations, personal plans for
 *   self-serve individuals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('phone', 20)->nullable()->unique()->after('email');
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
            $table->string('locale', 10)->nullable()->after('phone_verified_at');
            $table->timestamp('marketing_consent_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->timestamp('onboarded_at')->nullable();
        });

        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // signup | recovery | verify_contact
            $table->string('purpose', 20);
            // mail | sms
            $table->string('channel', 10);
            $table->string('destination_hash', 64);
            // Null for a decoy (address already taken or unknown): it can never be passed.
            $table->string('code_hash', 64)->nullable();
            $table->foreignUlid('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('partner_id')->nullable()->constrained()->nullOnDelete();
            // Encrypted pending details (sign-up form, new address).
            $table->text('payload')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('sends')->default(1);
            $table->timestamp('last_sent_at');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['destination_hash', 'created_at']);
            $table->index(['ip_hash', 'created_at']);
        });

        Schema::create('user_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            // sha256 of the session id: the id itself would let someone take the session.
            $table->string('session_hash', 64)->unique();
            $table->string('browser', 60)->nullable();
            $table->string('platform', 60)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->string('audience', 20)->default('business')->after('key');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('audience');
        });
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('otp_challenges');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropColumn(['phone', 'phone_verified_at', 'locale', 'marketing_consent_at', 'password_changed_at', 'recovered_at', 'onboarded_at']);
        });
        // Email stays nullable on the way down: phone-only people may exist by then.
    }
};
