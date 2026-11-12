<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A pharmacy sale may be made from a doctor's prescription (Outpatient): its
 * id is kept, so the counter can warn when the same prescription is sold again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_sale_links', function (Blueprint $table) {
            $table->ulid('consultation_id')->nullable()->after('prescriber_bmdc');
            $table->index(['organization_id', 'consultation_id'], 'pharmacy_sale_links_consultation_index');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_sale_links', function (Blueprint $table) {
            $table->dropIndex('pharmacy_sale_links_consultation_index');
            $table->dropColumn('consultation_id');
        });
    }
};
