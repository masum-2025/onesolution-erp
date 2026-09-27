<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Countries and languages (Phase 6).
 *
 * - countries: mirror of database/data/countries/XX.php (countries:sync);
 *   the data files are the source of truth. A country missing from the
 *   files is kept, marked inactive (organizations may still use it).
 * - users.timezone: the person's own timezone for showing times (UTC in the
 *   database; null = the organization's, then the country's).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('code', 2)->unique();
            $table->json('name');
            $table->char('currency_code', 3);
            $table->unsignedTinyInteger('currency_decimals');
            $table->string('date_format', 20);
            $table->string('week_start', 3);
            $table->json('weekend_days');
            // MM-DD
            $table->string('fiscal_year_start', 5);
            // dial, trunk, national pattern, example
            $table->json('phone_format');
            $table->json('address_format');
            $table->string('tax_profile_key', 40);
            $table->string('data_residency_region', 20);
            $table->string('default_locale', 10);
            $table->json('locales');
            $table->string('default_timezone', 64);
            $table->json('payment_gateways');
            $table->json('merchant_gateways');
            // active | inactive (removed from the data files)
            $table->string('status', 20);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });

        Schema::dropIfExists('countries');
    }
};
