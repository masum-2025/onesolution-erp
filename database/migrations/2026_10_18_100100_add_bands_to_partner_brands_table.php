<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brand bands: a thin strip of the brand's colors along the bottom edge of the
 * header (up to four colors, e.g. those of the logo) and, level with it, one
 * color under the sidebar's brand row. Empty = no band.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_brands', function (Blueprint $table) {
            $table->json('band_colors')->nullable();
            $table->char('side_band_color', 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('partner_brands', function (Blueprint $table) {
            $table->dropColumn(['band_colors', 'side_band_color']);
        });
    }
};
