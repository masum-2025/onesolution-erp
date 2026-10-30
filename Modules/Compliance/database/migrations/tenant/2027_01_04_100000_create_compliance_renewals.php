<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * CMP-2: renewals with the provider (in the client's own database).
 *
 * - cmp_requests: a renewal request for one register line, sent to its
 *   provider over the business connection (or kept as a record when the
 *   provider is not on the platform); the provider's answers update it.
 * - cmp_results: an audit result the provider sent; it changes the register
 *   only when someone of the client accepts it (or the rule says at once).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cmp_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // The factory (company or branch) of the line.
            $table->ulid('organization_id')->index();
            $table->ulid('company_id')->index();
            $table->foreignUlid('audit_id')->constrained('cmp_audits')->restrictOnDelete();
            $table->foreignUlid('provider_id')->constrained('cmp_providers')->restrictOnDelete();
            $table->ulid('connection_id')->nullable()->index();
            // The connection message that carried it (central connection_messages).
            $table->ulid('message_id')->nullable();
            $table->string('status', 12);
            $table->date('wanted_by')->nullable();
            $table->text('note')->nullable();
            $table->date('planned_on')->nullable();
            $table->string('reply_note', 1000)->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->ulid('last_reply_id')->nullable();
            $table->ulid('requested_by')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['audit_id', 'status']);
        });

        Schema::create('cmp_results', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            // Null when the line is not in the register yet: accepting it adds the line.
            $table->foreignUlid('audit_id')->nullable()->constrained('cmp_audits')->restrictOnDelete();
            $table->foreignUlid('request_id')->nullable()->constrained('cmp_requests')->restrictOnDelete();
            $table->ulid('connection_id')->index();
            // Delivered once: the same message again finds this row.
            $table->ulid('message_id')->unique();
            $table->string('from_name', 190);
            $table->string('service_code', 30);
            $table->string('service_name', 190)->nullable();
            $table->date('last_audit_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('result', 60)->nullable();
            $table->string('certificate_no', 100)->nullable();
            $table->string('audit_type_code', 30)->nullable();
            $table->text('note')->nullable();
            // Copied into the client's own storage on delivery: [{path, name, mime, size, sha256}].
            $table->json('files')->nullable();
            $table->string('status', 10);
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cmp_results');
        Schema::dropIfExists('cmp_requests');
    }
};
