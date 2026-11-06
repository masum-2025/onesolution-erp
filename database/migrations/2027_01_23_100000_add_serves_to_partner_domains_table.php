<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * MKT-3c: what a partner's own domain opens: its app (sign-in, console,
 * clients' work) or its company website. Every domain added so far opens
 * the app, so that stays the default. A client's own domain is always app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_domains', function (Blueprint $table) {
            $table->string('serves', 20)->default('app')->after('host');
        });
    }

    public function down(): void
    {
        Schema::table('partner_domains', function (Blueprint $table) {
            $table->dropColumn('serves');
        });
    }
};
