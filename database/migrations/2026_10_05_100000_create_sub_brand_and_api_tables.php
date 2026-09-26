<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 5B-5: client sub-brands, partner API keys and invitations.
 *
 * - client_brands: a client's own name, logo and color (where its partner
 *   allows sub-brands), shown to the client's own people.
 * - partner_api_keys: keys a partner's systems use to provision clients,
 *   members and plans. Only a hash of the secret is stored.
 * - api_idempotency: the answer to a write sent with an Idempotency-Key, so
 *   a retried request never does the work twice. Kept for a day.
 * - invitations: a person added to an organization sets their password
 *   through a one-time link. Only a hash of the token is stored.
 * - audit_logs.api_key_id: which API key made a change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_brands', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // The client's top organization.
            $table->foreignUlid('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('display_name', 60)->nullable();
            $table->char('primary_color', 7)->nullable();
            $table->string('logo_path')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('partner_api_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('partner_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            // Shown in lists so people recognise a key: osk_ab12cd34.
            $table->string('prefix', 20)->unique();
            $table->char('secret_hash', 64);
            $table->json('scopes');
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            // Actions through the key are made in this person's name; the key stops if they leave.
            $table->ulid('created_by');
            $table->timestamps();

            $table->index(['partner_id', 'revoked_at']);
        });

        Schema::create('api_idempotency', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('api_key_id')->constrained('partner_api_keys')->cascadeOnDelete();
            $table->string('idempotency_key', 100);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('status');
            $table->longText('response');
            $table->timestamp('created_at')->nullable();

            $table->unique(['api_key_id', 'idempotency_key'], 'api_idempotency_once');
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->ulid('invited_by')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'accepted_at']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->ulid('api_key_id')->nullable()->after('actor_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('api_key_id');
        });
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('api_idempotency');
        Schema::dropIfExists('partner_api_keys');
        Schema::dropIfExists('client_brands');
    }
};
