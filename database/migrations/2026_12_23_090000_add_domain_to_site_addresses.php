<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A public site's own domain (WEB-3a): "www.abcschool.edu.bd" pointing at the
 * sites server. It works only once proven by a DNS TXT record carrying the
 * token (verified_at set); one domain per site, never another's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_addresses', function (Blueprint $table) {
            $table->string('domain', 253)->nullable()->unique();
            $table->string('domain_token', 64)->nullable();
            $table->timestamp('domain_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_addresses', function (Blueprint $table) {
            $table->dropUnique(['domain']);
            $table->dropColumn(['domain', 'domain_token', 'domain_verified_at']);
        });
    }
};
