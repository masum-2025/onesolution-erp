<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A short line shown above every page of a website (WEB-3c), written as the
 * school wants it (a motto, or a line in Arabic for a madrasa). Plain text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_sites', function (Blueprint $table) {
            $table->string('motto', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('web_sites', function (Blueprint $table) {
            $table->dropColumn('motto');
        });
    }
};
