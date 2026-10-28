<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business connections (NET-1): two clients of the platform working together,
 * a service provider (a company) and its client (a group or a company). They
 * span two tenants, so they live in the main database, never in either
 * client's own one.
 *
 * - business_connections: one link, made by the provider (an invitation code,
 *   only its hash kept) and accepted by the client. invited → active, then
 *   paused (either side) or ended (either side, with a reason; final).
 * - business_connection_units: which of the client's companies and branches
 *   (factories) the provider may deal with; a unit covers the units below it.
 *   Only the client changes them; a removed unit keeps its row (removed_at).
 * - connection_messages: everything one side sends the other (a renewal
 *   request, a quotation, a report), as an envelope. The payload is stored
 *   encrypted only until it is delivered into the receiver's own data, then
 *   wiped; the envelope stays as the record of what was sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('provider_id')->constrained('organizations');
            $table->foreignUlid('client_id')->nullable()->constrained('organizations');
            // invited | active | paused | ended
            $table->string('status', 20);
            // The provider's own name for whom it invited ("Rider Group").
            $table->string('note', 150)->nullable();
            $table->string('code_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at')->nullable();
            $table->foreignUlid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignUlid('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            // provider | client: who paused it (only they resume).
            $table->string('paused_by_side', 10)->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->string('ended_by_side', 10)->nullable();
            $table->foreignUlid('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['provider_id', 'status']);
            $table->index(['client_id', 'status']);
        });

        Schema::create('business_connection_units', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('connection_id')->constrained('business_connections')->cascadeOnDelete();
            $table->foreignUlid('unit_id')->constrained('organizations');
            $table->foreignUlid('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->foreignUlid('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['connection_id', 'removed_at']);
            $table->index('unit_id');
        });

        Schema::create('connection_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('connection_id')->constrained('business_connections')->cascadeOnDelete();
            // to_client | to_provider
            $table->string('direction', 12);
            // "{module}.{kind}", e.g. compliance.renewal_request
            $table->string('kind', 60);
            $table->foreignUlid('from_unit_id')->constrained('organizations');
            $table->foreignUlid('to_unit_id')->constrained('organizations');
            $table->foreignUlid('sent_by')->nullable()->constrained('users')->nullOnDelete();
            // The same op_id again is the same message (a retry), never a second one.
            $table->string('op_id', 64);
            // Encrypted; null once delivered (or given up).
            $table->longText('payload')->nullable();
            // Files to copy: [{path, name, mime, size, sha256}] on the sender's side; null once delivered.
            $table->json('files')->nullable();
            // queued | delivered | failed
            $table->string('status', 12);
            $table->unsignedSmallInteger('attempts')->default(0);
            // A stable error code, never details.
            $table->string('error', 60)->nullable();
            // The receiving module's own record, when it made one.
            $table->string('received_as', 64)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'op_id']);
            $table->index(['connection_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_messages');
        Schema::dropIfExists('business_connection_units');
        Schema::dropIfExists('business_connections');
    }
};
