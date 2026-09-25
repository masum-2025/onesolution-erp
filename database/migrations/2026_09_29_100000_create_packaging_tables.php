<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 5: plans, sector packages and what was applied to each organization.
 *
 * plans, plan_prices and sector_packages mirror the data files
 * (database/seeders/data/plans.php, sector-packages.php) via
 * `php artisan packaging:sync`; removed entries are deprecated, never deleted.
 * Money is integer minor units + currency code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key', 50)->unique();
            // Module keys, or ["*"].
            $table->json('modules');
            $table->boolean('is_public')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('deprecated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('plan_prices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('plan_id')->constrained()->cascadeOnDelete();
            $table->char('currency_code', 3);
            $table->string('period', 10);
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();

            $table->unique(['plan_id', 'currency_code', 'period']);
        });

        Schema::create('sector_packages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key', 50)->unique();
            $table->json('modules');
            $table->json('rules');
            $table->json('role_templates');
            $table->string('demo_seeder')->nullable();
            $table->string('version', 20);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('deprecated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('organization_packages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->string('package_key', 50);
            $table->string('package_version', 20);
            $table->ulid('applied_by')->nullable();
            // What was turned on, set, created or skipped, and why.
            $table->json('summary');
            $table->timestamps();

            // A package is applied once per organization.
            $table->unique(['organization_id', 'package_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_packages');
        Schema::dropIfExists('sector_packages');
        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('plans');
    }
};
