<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A client's own payment gateway accounts (Phase 6).
 *
 * - merchant_accounts: one per company and gateway. Credentials are
 *   encrypted with the app key and never leave the server; people see a
 *   short hint only. A change (new credentials or mode) waits in the
 *   pending_* columns until a second person approves it, or until the
 *   single-approver wait ends; the active credentials keep working
 *   meanwhile, so a change never stops payments half way.
 * - payments: who collects the money (null merchant account = the
 *   platform, as in Phase 5C-2), and what a collection pays for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->string('gateway', 30);
            $table->string('label', 100);
            $table->char('currency_code', 3);
            // pending (never approved yet) | active | disabled
            $table->string('status', 20);
            // The approved credentials and mode (null until the first approval).
            $table->string('mode', 10)->nullable();
            $table->text('credentials')->nullable();
            $table->string('credential_hint', 40)->nullable();
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            // A change waiting for approval.
            $table->string('pending_mode', 10)->nullable();
            $table->text('pending_credentials')->nullable();
            $table->string('pending_hint', 40)->nullable();
            $table->foreignUlid('pending_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('pending_at')->nullable();
            // Nobody else could approve: the change takes effect at this time by itself.
            $table->timestamp('activates_at')->nullable();
            // Last connection test: ok, or an error code (never the gateway's raw text).
            $table->string('check_result', 40)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'gateway']);
            $table->index('activates_at');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignUlid('merchant_account_id')->nullable()->after('partner_id')->constrained('merchant_accounts');
            // A collection: which module's record the customer pays for, e.g. "school.fee" + id.
            $table->string('subject_type', 60)->nullable()->after('invoice_id');
            $table->string('subject_id', 26)->nullable()->after('subject_type');

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['subject_type', 'subject_id']);
            $table->dropConstrainedForeignId('merchant_account_id');
            $table->dropColumn(['subject_type', 'subject_id']);
        });

        Schema::dropIfExists('merchant_accounts');
    }
};
