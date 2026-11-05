<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company websites (MKT-1): a partner's own marketing website, built from
 * pages of blocks. The house partner's is the platform's (One Solutions).
 *
 * mkt_sites: one per partner; template, colours, header, footer, contact,
 *   notice bar and search settings (settings), and the published snapshot
 *   the public sees (published). Drafts are the pages and blocks below.
 * mkt_pages: a page per slug (the home page has is_home), its title and
 *   search details per language.
 * mkt_blocks: a page's sections in order; texts per language inside data,
 *   never HTML. A block can show only between two days (an offer).
 * mkt_versions: earlier published snapshots, to go back to one.
 * mkt_redirects: an old address sent on to a new one (301).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mkt_sites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('partner_id')->unique()->constrained()->cascadeOnDelete();
            // aurora | corporate | studio
            $table->string('template', 20);
            $table->json('settings');
            $table->json('locales');
            $table->string('default_locale', 10);
            // draft | published | offline
            $table->string('status', 20);
            $table->json('published')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->ulid('published_by')->nullable();
            // Changes with every publish (kept pages start afresh).
            $table->unsignedInteger('cache_version')->default(1);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('mkt_pages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('mkt_sites')->cascadeOnDelete();
            // Lower case words joined by "-"; "" for the home page.
            $table->string('slug', 80);
            $table->boolean('is_home')->default(false);
            $table->json('title');
            // {title, description} per language, image url, noindex.
            $table->json('seo')->nullable();
            $table->boolean('in_menu')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['site_id', 'slug']);
        });

        Schema::create('mkt_blocks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('mkt_sites')->cascadeOnDelete();
            $table->foreignUlid('page_id')->constrained('mkt_pages')->cascadeOnDelete();
            $table->string('type', 30);
            $table->json('data');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            // Shown only from / until these days (the site's own days), e.g. an offer.
            $table->date('visible_from')->nullable();
            $table->date('visible_until')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['page_id', 'sort_order']);
        });

        Schema::create('mkt_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('mkt_sites')->cascadeOnDelete();
            $table->json('snapshot');
            $table->ulid('published_by')->nullable();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->index(['site_id', 'published_at']);
        });

        Schema::create('mkt_redirects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('mkt_sites')->cascadeOnDelete();
            $table->string('from_path', 200);
            $table->string('to_path', 300);
            $table->unsignedInteger('hits')->default(0);
            $table->timestamps();

            $table->unique(['site_id', 'from_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mkt_redirects');
        Schema::dropIfExists('mkt_versions');
        Schema::dropIfExists('mkt_blocks');
        Schema::dropIfExists('mkt_pages');
        Schema::dropIfExists('mkt_sites');
    }
};
