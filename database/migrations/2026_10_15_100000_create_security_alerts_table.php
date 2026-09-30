<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security alerts (Phase 9-2): raised when security events pass a
 * threshold (config/monitoring.php). One row per kind and group (an account,
 * an address, an organization) while it stays active; repeats count up
 * instead of telling people again. Platform data: the organization and
 * partner columns say what an alert is about, for their own notices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_alerts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('kind', 60);
            // low | medium | high
            $table->string('severity', 10);
            // sha1 of kind + group: finds the open alert to count up.
            $table->string('fingerprint', 40)->index();
            $table->ulid('partner_id')->nullable()->index();
            $table->ulid('organization_id')->nullable()->index();
            $table->ulid('user_id')->nullable();
            $table->unsignedInteger('count');
            $table->json('details')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledged_by', 100)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_alerts');
    }
};
