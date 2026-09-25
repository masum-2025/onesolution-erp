<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Interim plan per organization (null = inherit). Commercial data: set by
     * the partner / platform, never by the client. Phase 5 adds the plans table.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('plan_key', 50)->nullable()->after('sector_key');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('plan_key');
        });
    }
};
