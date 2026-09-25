<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A token carries at most one context: an organization (client area) or a
     * partner (partner console). The active tenant is always read from here,
     * never from the request body.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignUlid('organization_id')->nullable()->after('abilities')
                ->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('partner_id')->nullable()->after('organization_id')
                ->constrained('partners')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
            $table->dropConstrainedForeignId('partner_id');
        });
    }
};
