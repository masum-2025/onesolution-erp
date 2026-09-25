<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 4: roles and permissions.
 *
 * - permissions: mirror of the permission catalog (core + manifests), synced by
 *   `php artisan access:sync`; removed permissions are marked deprecated, never deleted.
 * - role_templates: sector role templates (data), cloned into organizations.
 * - roles: owned by an organization, usable there and in every unit below it.
 * - membership_roles: roles held by one membership (one person in one organization).
 *
 * A membership can hold several roles, so the single, never-used
 * organization_user.role_id column from Phase 1 is replaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key', 100)->unique();
            $table->string('module_key', 50)->index();
            $table->timestamp('deprecated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('role_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key', 50)->unique();
            // null = every sector.
            $table->string('sector_key', 50)->nullable()->index();
            // Permission patterns ("payroll.*", "*.view", "!payroll.approve").
            $table->json('permissions');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('deprecated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->string('key', 60);
            // Translatable: {"en": "...", "bn": "..."}
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('template_key', 50)->nullable();
            // Optimistic concurrency: an update must send the version it started from.
            $table->unsignedInteger('version')->default(1);
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'key']);
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignUlid('role_id')->constrained()->cascadeOnDelete();
            $table->string('permission_key', 100);
            $table->foreign('permission_key')->references('key')->on('permissions')->restrictOnDelete();

            $table->primary(['role_id', 'permission_key']);
        });

        Schema::create('membership_roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // The membership's organization (tenant scope).
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('membership_id')->constrained('organization_user')->cascadeOnDelete();
            // A role in use cannot be deleted.
            $table->foreignUlid('role_id')->constrained()->restrictOnDelete();
            $table->ulid('assigned_by')->nullable();
            $table->timestamps();

            $table->unique(['membership_id', 'role_id']);
        });

        Schema::table('organization_user', function (Blueprint $table) {
            $table->dropIndex(['role_id']);
            $table->dropColumn('role_id');
        });
    }

    public function down(): void
    {
        Schema::table('organization_user', function (Blueprint $table) {
            $table->ulid('role_id')->nullable()->index();
        });

        Schema::dropIfExists('membership_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('role_templates');
        Schema::dropIfExists('permissions');
    }
};
