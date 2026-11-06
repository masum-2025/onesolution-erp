<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * MKT-3a: a company website's pictures. The files sit on the private disk
 * (two WebP copies of each upload); this row says whose they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mkt_media', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained('mkt_sites')->cascadeOnDelete();
            $table->string('name', 150);
            // Words read out instead of the picture (per language); a section's own caption wins.
            $table->json('alt')->nullable();
            $table->string('mime', 50);
            $table->unsignedInteger('size_bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->json('paths');
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mkt_media');
    }
};
