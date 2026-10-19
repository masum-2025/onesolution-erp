<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A school's public website (WEB-1).
 *
 * - web_sites: one per company: template, colours, names, contact, and the
 *   published snapshot (pages and blocks as they were when published, so
 *   editing never changes the live site until the next publish).
 * - web_pages + web_blocks: the draft pages, each a list of blocks of known
 *   types (never raw HTML); texts per language inside the block data.
 * - web_notices: the notice board, published one by one.
 * - web_media: images (with smaller copies) and PDFs, kept outside public.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_sites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->unique();
            $table->string('slug', 63);
            $table->string('template', 30);
            // { primary: "#1d4ed8", accent: "#f59e0b" }
            $table->json('theme')->nullable();
            $table->ulid('logo_media_id')->nullable();
            $table->json('title');
            $table->json('tagline')->nullable();
            // { address: {en, bn}, phone, email, map_url, hours: {en, bn} }
            $table->json('contact')->nullable();
            $table->json('locales');
            $table->string('default_locale', 10);
            // draft (never published), published, offline (taken down; content kept).
            $table->string('status', 20);
            $table->longText('published')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('cache_version')->default(1);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('web_pages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('site_id');
            $table->string('slug', 60);
            $table->json('title');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('in_menu')->default(true);
            $table->boolean('is_home')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['site_id', 'slug']);
        });

        Schema::create('web_blocks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('page_id');
            $table->string('type', 30);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('data');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['page_id', 'sort_order']);
        });

        Schema::create('web_notices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('site_id');
            $table->json('title');
            $table->json('body')->nullable();
            $table->ulid('media_id')->nullable();
            $table->date('published_on');
            $table->boolean('pinned')->default(false);
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['site_id', 'is_published', 'published_on']);
        });

        Schema::create('web_media', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('site_id');
            // image | pdf
            $table->string('kind', 10);
            $table->string('name', 150);
            $table->string('mime', 60);
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            // Stored file paths on the private disk: { original, large, small }.
            $table->json('paths');
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_media');
        Schema::dropIfExists('web_notices');
        Schema::dropIfExists('web_blocks');
        Schema::dropIfExists('web_pages');
        Schema::dropIfExists('web_sites');
    }
};
