<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('partner_id')->constrained('partners')->restrictOnDelete();
            $table->ulid('parent_id')->nullable();
            // The top group of this tree (equals id for the root). No FK so the root can point at itself.
            $table->ulid('root_id')->index();
            // Materialized path of ids from the root down to this row: "/{root}/{child}/{id}/".
            $table->string('path', 255);
            $table->unsignedSmallInteger('depth');
            $table->string('type', 20)->index();
            // Translatable: {"en": "...", "bn": "..."}
            $table->json('name');
            $table->string('sector_key', 50)->nullable()->index();
            // Null means "inherit from the nearest ancestor that sets it".
            $table->char('country_code', 2)->nullable();
            $table->string('default_locale', 10)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->string('region', 10)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->json('settings')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            // Every subtree query filters by partner first, then by path prefix.
            $table->index(['partner_id', 'path']);
        });

        // A key to the same table is added once its primary key exists (PostgreSQL).
        Schema::table('organizations', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('organizations')->restrictOnDelete();
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('membership_type', 20)->default('staff');
            // Foreign key to roles is added in Phase 4.
            $table->ulid('role_id')->nullable()->index();
            $table->string('access_scope', 20)->default('own');
            $table->boolean('is_primary')->default(false);
            $table->string('status', 20)->default('active');
            $table->foreignUlid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }
};
