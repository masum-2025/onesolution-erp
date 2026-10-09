<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * LANG-1: languages and wording kept in the database, on top of the
 * translation files that ship with the code.
 *
 * - languages: languages added by the platform without a deploy (Hindi,
 *   Arabic, …). The file languages (config tenancy.supported_locales) are
 *   not rows here; they always exist.
 * - translation_overrides: one text for one key, at one level (platform,
 *   partner, or an organization: group or company). A database language's
 *   own texts are its platform rows.
 * - translation_versions: a counter per level, raised on every change. The
 *   browser caches texts under a hash of these, so unchanged levels cost
 *   no request at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // BCP 47, e.g. "hi", "ar", "pt-BR".
            $table->string('code', 20)->unique();
            // In the language itself ("हिन्दी") and in English ("Hindi").
            $table->string('name', 60);
            $table->string('english_name', 60);
            // ltr | rtl
            $table->string('direction', 3);
            // A file language shown where this one has no text yet.
            $table->string('fallback', 20);
            // draft | published | disabled
            $table->string('status', 12);
            $table->timestamp('published_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('translation_overrides', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // platform | partner | organization; scope_id is '' for the platform.
            $table->string('scope_type', 12);
            $table->string('scope_id', 26);
            $table->string('locale', 20);
            // ui (browser texts) | server (messages, emails)
            $table->string('channel', 10);
            $table->string('key', 190);
            $table->text('value');
            $table->unsignedInteger('version')->default(1);
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id', 'locale', 'channel', 'key'], 'translation_overrides_text_unique');
            $table->index(['locale', 'channel'], 'translation_overrides_locale_index');
        });

        Schema::create('translation_versions', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 12);
            $table->string('scope_id', 26);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id'], 'translation_versions_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_versions');
        Schema::dropIfExists('translation_overrides');
        Schema::dropIfExists('languages');
    }
};
