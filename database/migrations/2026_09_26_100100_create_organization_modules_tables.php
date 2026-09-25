<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Module on/off per organization level. No row = inherit.
        Schema::create('organization_modules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('module_key', 50);
            $table->string('state', 20)->default('inherit');
            // Children cannot change this module while locked.
            $table->boolean('locked')->default(false);
            $table->json('settings')->nullable();
            $table->foreignUlid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'module_key']);
            $table->index('module_key');
        });

        // Explicit admin consent, required before modules that set requires_consent (AI) can run.
        Schema::create('module_consents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('module_key', 50);
            $table->string('terms_version', 20);
            $table->foreignUlid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at');
            $table->foreignUlid('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'module_key']);
        });

        // Delayed, cancellable deletion of a disabled module's data.
        Schema::create('module_purge_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('module_key', 50);
            $table->string('status', 20)->default('pending')->index();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('execute_after')->index();
            $table->foreignUlid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->json('result')->nullable();
            $table->text('reason');
            $table->timestamps();

            $table->index(['organization_id', 'module_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_purge_requests');
        Schema::dropIfExists('module_consents');
        Schema::dropIfExists('organization_modules');
    }
};
