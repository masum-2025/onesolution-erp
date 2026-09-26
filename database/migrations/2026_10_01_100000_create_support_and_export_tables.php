<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 5B-2: break-glass support access, client data export, and when a
 * partner was suspended (its clients get a read-only grace period).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_grants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('partner_id')->constrained()->restrictOnDelete();
            // The client organization the access is for (and everything below it).
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->string('severity', 20);
            // Read-only for now; write access would need its own approval flow.
            $table->string('access', 10)->default('read');
            $table->unsignedSmallInteger('duration_minutes');
            // pending | approved | rejected | revoked | expired
            $table->string('status', 20)->index();
            $table->boolean('auto_approved')->default(false);
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('data_exports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('requested_by')->constrained('users')->restrictOnDelete();
            // queued | running | ready | failed | expired
            $table->string('status', 20)->index();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            // Datasets and row counts, for the client to see what is inside.
            $table->json('summary')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('suspended_at');
        });

        Schema::dropIfExists('data_exports');
        Schema::dropIfExists('support_grants');
    }
};
