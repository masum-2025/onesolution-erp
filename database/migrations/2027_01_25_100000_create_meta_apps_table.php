<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SC-1: a partner's own Meta app (platform database).
 *
 * - meta_apps: one per partner. The secret is encrypted at rest. A change
 *   waits in pending_* for a second partner owner (or the single-approver
 *   wait); the approved values keep working until then.
 * - meta_deletion_requests: data deletion requests Meta sends for a person
 *   who removed the app (the confirmation code Meta shows them). The person
 *   is kept only as a hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_apps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('partner_id')->unique()->constrained('partners')->cascadeOnDelete();
            $table->string('app_id', 40)->nullable();
            $table->text('app_secret')->nullable();
            // The app's name at Meta, read when the credentials were checked.
            $table->string('app_name', 150)->nullable();
            // pending (never approved yet), active, disabled
            $table->string('status', 20);
            $table->string('pending_app_id', 40)->nullable();
            $table->text('pending_app_secret')->nullable();
            $table->string('pending_app_name', 150)->nullable();
            $table->ulid('pending_by')->nullable();
            $table->timestamp('pending_at')->nullable();
            // With no second owner: when the pending change applies by itself.
            $table->timestamp('activates_at')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index('activates_at');
        });

        Schema::create('meta_deletion_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('meta_app_id')->constrained('meta_apps')->cascadeOnDelete();
            $table->string('code', 40)->unique();
            $table->string('person_hash', 64);
            // How many connected pages were removed.
            $table->unsignedInteger('pages_removed')->default(0);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_deletion_requests');
        Schema::dropIfExists('meta_apps');
    }
};
