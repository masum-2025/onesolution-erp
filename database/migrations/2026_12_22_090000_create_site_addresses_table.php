<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Public sites (WEB-1): which organization a site address belongs to, so a
 * request for "abc-school.<sites domain>" or "/s/abc-school" finds the
 * right client (whose content may live in its own database). One address
 * per organization and module (edu_website today). A client's own domain
 * is a verified partner_domains row for that organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_addresses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('module', 50);
            // Lower-case letters, digits and hyphens: a DNS label.
            $table->string('slug', 63)->unique();
            $table->timestamps();

            $table->unique(['organization_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_addresses');
    }
};
