<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 5B-3b: branded email and SMS.
 *
 * - partner_mail_domains: a partner's own sending domain. Used only after
 *   ownership, SPF, DKIM and DMARC are verified in DNS. The DKIM private key
 *   is encrypted and never leaves the server.
 * - partner_sms_senders: a partner's SMS sender ID (approved by the platform
 *   where operators require registration).
 * - notification_templates: a partner's own wording for a notification,
 *   per channel and language. Without one, the platform default applies.
 * - notification_deliveries: what was sent to whom (address masked). The
 *   message data is removed once sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_mail_domains', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('partner_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('domain')->unique();
            $table->string('verification_token', 64);
            $table->string('dkim_selector', 30);
            $table->text('dkim_private_key');
            $table->text('dkim_public_key');
            // no-reply@{domain}; display name and reply-to fall back to the brand.
            $table->string('local_part', 64)->default('no-reply');
            $table->string('from_name', 80)->nullable();
            $table->string('reply_to')->nullable();
            // {"ownership": bool, "spf": bool, "dkim": bool, "dmarc": bool}
            $table->json('checks')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('partner_sms_senders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('partner_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('sender_id', 11);
            // pending | approved | rejected
            $table->string('status', 20);
            $table->string('note', 300)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->ulid('requested_by')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('partner_id')->constrained()->cascadeOnDelete();
            $table->string('notification_key', 60);
            $table->string('channel', 10);
            $table->string('locale', 10);
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->unsignedInteger('version')->default(1);
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['partner_id', 'notification_key', 'channel', 'locale'], 'notification_templates_wording_unique');
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('partner_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notification_key', 60);
            $table->string('channel', 10);
            $table->string('locale', 10);
            // Masked: j***@example.com, +88017******45.
            $table->string('recipient', 120);
            // queued | sent | failed
            $table->string('status', 20);
            $table->string('sender', 255)->nullable();
            $table->string('error', 300)->nullable();
            // Placeholder values until sent; then removed.
            $table->text('data')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('partner_sms_senders');
        Schema::dropIfExists('partner_mail_domains');
    }
};
