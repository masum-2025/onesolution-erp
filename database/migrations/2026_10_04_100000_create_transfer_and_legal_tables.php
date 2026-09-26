<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 5B-4: client transfer and legal documents.
 *
 * - transfer_codes: a partner hands a client a one-time code to move to it.
 *   Only a hash is stored.
 * - client_transfers: a client moving from one partner to another (or back
 *   to the house partner), with the client's consent and the new partner's
 *   acceptance.
 * - legal_documents: terms, privacy notice and data processing agreement,
 *   the platform's (partner_id null) or a partner's, in versions that never
 *   change once published. Title and body are translatable.
 * - document_acceptances: which version a client accepted, who and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('partner_id')->constrained()->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            // Shown in the console so staff know which code they gave to whom.
            $table->string('label', 100)->nullable();
            $table->string('hint', 10);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'expires_at']);
        });

        Schema::create('client_transfers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('from_partner_id')->constrained('partners')->restrictOnDelete();
            $table->foreignUlid('to_partner_id')->constrained('partners')->restrictOnDelete();
            $table->foreignUlid('transfer_code_id')->nullable()->constrained()->nullOnDelete();
            // awaiting_partner | completed | rejected | cancelled
            $table->string('status', 20);
            $table->string('reason', 500);
            // The client's consent (null when the platform moved it, e.g. a closed partner).
            $table->ulid('requested_by')->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->boolean('by_platform')->default(false);
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            // What the move changes, as shown to the client before consenting.
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->index(['to_partner_id', 'status']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('legal_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // null = the platform's own document (the default for every partner).
            $table->foreignUlid('partner_id')->nullable()->constrained()->cascadeOnDelete();
            // terms | privacy | dpa
            $table->string('kind', 20);
            $table->unsignedInteger('version');
            // Translatable: {"en": "...", "bn": "..."}
            $table->json('title');
            $table->json('body');
            $table->string('summary', 500)->nullable();
            $table->timestamp('published_at');
            $table->ulid('published_by')->nullable();
            $table->timestamps();

            $table->unique(['partner_id', 'kind', 'version'], 'legal_documents_version_unique');
        });

        Schema::create('document_acceptances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('legal_document_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->unsignedInteger('version');
            $table->string('locale', 10);
            $table->ulid('accepted_by');
            $table->timestamp('accepted_at');
            $table->timestamps();

            $table->unique(['organization_id', 'legal_document_id'], 'document_acceptances_once');
            $table->index(['organization_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_acceptances');
        Schema::dropIfExists('legal_documents');
        Schema::dropIfExists('client_transfers');
        Schema::dropIfExists('transfer_codes');
    }
};
