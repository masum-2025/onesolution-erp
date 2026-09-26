<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Phase 5B-1: the partner layer.
 *
 * - partner_brands: one brand per partner (runtime tokens, never a build).
 *   Brand values that lived in partners.settings.brand are moved here.
 * - partner_domains: custom hosts; a host resolves to a partner (and maybe
 *   one client) only after DNS verification.
 * - partner_modules: modules turned on / off / locked by a partner for all
 *   of its clients.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_brands', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('partner_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('product_name', 60)->nullable();
            $table->char('primary_color', 7)->nullable();
            $table->char('secondary_color', 7)->nullable();
            $table->string('font_key', 30)->nullable();
            // Files on the private disk, served by /brand-assets/{partner}/{kind}.
            $table->string('logo_light_path')->nullable();
            $table->string('logo_dark_path')->nullable();
            $table->string('mark_path')->nullable();
            $table->string('favicon_path')->nullable();
            // Translatable: {"en": "...", "bn": "..."}
            $table->json('tagline')->nullable();
            $table->json('login_title')->nullable();
            $table->json('login_text')->nullable();
            $table->json('footer_text')->nullable();
            $table->string('support_email')->nullable();
            $table->string('support_phone', 30)->nullable();
            $table->string('terms_url', 500)->nullable();
            $table->string('privacy_url', 500)->nullable();
            // Bumped on every change: cache key for assets and the app shell.
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('partner_domains', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('partner_id')->constrained()->cascadeOnDelete();
            // A client's own domain (its top organization); null = the partner's.
            $table->foreignUlid('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('host')->unique();
            $table->string('status', 20)->default('pending')->index();
            $table->string('verification_token', 64);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('partner_modules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('partner_id')->constrained()->cascadeOnDelete();
            $table->string('module_key', 50);
            $table->string('state', 20);
            $table->boolean('locked')->default(false);
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['partner_id', 'module_key']);
        });

        // Move brand values from partners.settings.brand into partner_brands.
        foreach (DB::table('partners')->whereNotNull('settings')->get(['id', 'settings']) as $partner) {
            $brand = json_decode((string) $partner->settings, true)['brand'] ?? null;
            if (! is_array($brand)) {
                continue;
            }

            DB::table('partner_brands')->insert([
                'id' => (string) Str::ulid(),
                'partner_id' => $partner->id,
                'product_name' => is_string($brand['name'] ?? null) ? mb_substr($brand['name'], 0, 60) : null,
                'primary_color' => is_string($brand['primary_color'] ?? null) && preg_match('/^#[0-9A-Fa-f]{6}$/', $brand['primary_color']) ? strtoupper($brand['primary_color']) : null,
                'tagline' => is_array($brand['tagline'] ?? null) ? json_encode($brand['tagline']) : null,
                'support_email' => filter_var($brand['support_email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_modules');
        Schema::dropIfExists('partner_domains');
        Schema::dropIfExists('partner_brands');
    }
};
