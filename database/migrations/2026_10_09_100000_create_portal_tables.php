<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2B2C portals (Phase 5C-4).
 *
 * - portal_invitations: a client invites someone (a parent, an employee, a
 *   customer) to see one record (a child, their own payslips, their orders).
 *   The invitation is bound to an email or phone: only an account that has
 *   verified that address can use it. Only hashes of the link and the code
 *   are kept; the address is encrypted.
 * - portal_links: which records a portal member may see, and whether the
 *   client approved it. Modules only ever show a portal member the records
 *   of their active links (PortalAccess).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            // A record kind declared by a module (e.g. school.student) and its id there.
            $table->string('subject_type', 60);
            $table->ulid('subject_id');
            // guardian | self
            $table->string('relation', 20);
            $table->string('name', 150);
            // mail | sms, and the address (encrypted) with its hash for matching.
            $table->string('channel', 10);
            $table->text('contact');
            $table->string('contact_hash', 64);
            $table->string('token_hash', 64)->unique();
            $table->string('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignUlid('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'used_at', 'revoked_at']);
        });

        Schema::create('portal_links', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('membership_id')->constrained('organization_user')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users');
            $table->string('subject_type', 60);
            $table->ulid('subject_id');
            $table->string('relation', 20);
            // pending → active | rejected; active → revoked
            $table->string('status', 20);
            // invitation (with a verified email or phone)
            $table->string('linked_via', 20);
            $table->foreignUlid('invitation_id')->nullable()->constrained('portal_invitations')->nullOnDelete();
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['membership_id', 'subject_type', 'subject_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['membership_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_links');
        Schema::dropIfExists('portal_invitations');
    }
};
