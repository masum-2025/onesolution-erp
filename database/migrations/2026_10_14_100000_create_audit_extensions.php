<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit (Phase 9-1).
 *
 * - audit_logs.device_id / session_id: where a change came from: the offline
 *   device (Phase 7) or the browser session (user_sessions, Phase 5C-1).
 * - audit_exports: CSV exports of an organization's audit log (advanced_audit
 *   module), kept for a limited time like data exports.
 * - audit_ship_cursors: how far each external audit store has received
 *   entries, so none is skipped or sent twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->ulid('device_id')->nullable()->index();
            $table->ulid('session_id')->nullable();
            // Retention and reports read one organization's entries by time.
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('audit_exports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('requested_by');
            $table->string('status', 20);
            $table->json('filters')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('rows')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_ship_cursors', function (Blueprint $table) {
            $table->string('driver', 50)->primary();
            $table->timestamp('last_created_at')->nullable();
            $table->ulid('last_id')->nullable();
            $table->unsignedBigInteger('shipped')->default(0);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_ship_cursors');
        Schema::dropIfExists('audit_exports');

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'created_at']);
            $table->dropIndex(['device_id']);
            $table->dropColumn(['device_id', 'session_id']);
        });
    }
};
