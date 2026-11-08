<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * MKT-5b: a "look link" the owner hands to someone (a client, a colleague):
 * on the public website it shows the Customize panel to that person only, for
 * a few days. Only the link's hash is kept; saving nothing, it can be ended.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mkt_sites', function (Blueprint $table) {
            $table->string('look_key', 64)->nullable();
            $table->timestamp('look_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mkt_sites', function (Blueprint $table) {
            $table->dropColumn(['look_key', 'look_until']);
        });
    }
};
