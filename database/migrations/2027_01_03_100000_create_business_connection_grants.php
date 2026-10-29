<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connection grants (NET-2): what a client lets a connected service provider
 * do in its own data, module by module. One row per connection and module:
 * the module's own permissions given (never platform ones), until when, and
 * whether it was taken back. The provider's people then work inside the
 * client's shared factories with exactly those permissions; every change of
 * a grant is audited on both sides.
 *
 * business_connections.last_used_at: when the provider last worked in the
 * client's data (shown to both sides).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_connection_grants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('connection_id')->constrained('business_connections')->cascadeOnDelete();
            $table->string('module', 50);
            // e.g. ["compliance.view", "compliance.set_expiry"]
            $table->json('permissions');
            $table->timestamp('expires_at')->nullable();
            $table->foreignUlid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUlid('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['connection_id', 'module']);
        });

        Schema::table('business_connections', function (Blueprint $table) {
            $table->timestamp('last_used_at')->nullable()->after('end_reason');
        });
    }

    public function down(): void
    {
        Schema::table('business_connections', function (Blueprint $table) {
            $table->dropColumn('last_used_at');
        });
        Schema::dropIfExists('business_connection_grants');
    }
};
