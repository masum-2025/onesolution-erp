<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compliance (CMP-1): a client's audit register.
 *
 * - cmp_services, cmp_audit_types, cmp_providers: the lists, kept by a group
 *   or a company (organization_id) and used by every unit below it. Names per
 *   language. Switched off, never removed. A provider may be one connected on
 *   the platform (connection_id, NET-1).
 * - cmp_audits: the register, one row per factory (a company or a branch) and
 *   audit service: provider, kind, last audit day, the official expiry day,
 *   result and certificate. Days left are always worked out from the expiry
 *   day, never stored.
 * - cmp_audit_changes: every change of the official dates, provider or result,
 *   with its reason (append-only).
 * - cmp_reminders_sent: which reminder stage was sent for which expiry day, so
 *   none goes twice; a new expiry day starts the stages again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cmp_services', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('code', 30);
            $table->json('name');
            // How long a certificate usually lasts: proposes the expiry day from the audit day.
            $table->unsignedSmallInteger('validity_months')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('cmp_audit_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('code', 30);
            $table->json('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('cmp_providers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('name', 150);
            $table->string('email', 190)->nullable();
            $table->string('phone', 30)->nullable();
            // The provider on the platform, when connected (business_connections, main database).
            $table->ulid('connection_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('cmp_audits', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // The factory: a company or a branch.
            $table->ulid('organization_id')->index();
            $table->ulid('company_id')->index();
            $table->foreignUlid('service_id')->constrained('cmp_services')->restrictOnDelete();
            $table->foreignUlid('provider_id')->nullable()->constrained('cmp_providers')->restrictOnDelete();
            $table->foreignUlid('audit_type_id')->nullable()->constrained('cmp_audit_types')->restrictOnDelete();
            $table->date('last_audit_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('result', 60)->nullable();
            $table->string('certificate_no', 100)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'service_id']);
            $table->index(['company_id', 'expires_on']);
        });

        Schema::create('cmp_audit_changes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('audit_id')->constrained('cmp_audits')->cascadeOnDelete();
            // added | dates | details | imported
            $table->string('kind', 20);
            $table->json('old')->nullable();
            $table->json('new');
            $table->string('reason', 500)->nullable();
            $table->ulid('user_id')->nullable();
            $table->timestamp('created_at');

            $table->index(['audit_id', 'created_at']);
        });

        Schema::create('cmp_reminders_sent', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('audit_id')->constrained('cmp_audits')->cascadeOnDelete();
            $table->date('expires_on');
            // Days before the expiry day, or -1 once expired.
            $table->smallInteger('stage');
            $table->date('sent_on');
            $table->timestamp('created_at');

            $table->unique(['audit_id', 'expires_on', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cmp_reminders_sent');
        Schema::dropIfExists('cmp_audit_changes');
        Schema::dropIfExists('cmp_audits');
        Schema::dropIfExists('cmp_providers');
        Schema::dropIfExists('cmp_audit_types');
        Schema::dropIfExists('cmp_services');
    }
};
