<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Search and sharing (WEB-3a): a page's own description (per language) and
 * picture for links shared on Facebook or WhatsApp; the site's picture for
 * pages without one. Pictures are the site's media.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_pages', function (Blueprint $table) {
            $table->json('description')->nullable();
            $table->ulid('share_media_id')->nullable();
        });
        Schema::table('web_sites', function (Blueprint $table) {
            $table->ulid('share_media_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('web_pages', function (Blueprint $table) {
            $table->dropColumn(['description', 'share_media_id']);
        });
        Schema::table('web_sites', function (Blueprint $table) {
            $table->dropColumn('share_media_id');
        });
    }
};
