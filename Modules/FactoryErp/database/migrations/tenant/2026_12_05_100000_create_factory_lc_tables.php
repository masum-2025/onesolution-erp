<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * FAC-5 (letters of credit), in the client's database:
 * - fac_lcs: a buyer's letter of credit: number, banks, amount and
 *   currency (minor units) with a tolerance, the last day to ship and the
 *   expiry, whether partial shipments and transshipment are allowed,
 *   ports, payment days (0 = at sight), the documents it asks for.
 * - fac_lc_orders: the orders it pays for (an order under one L/C).
 * - fac_shipments gains the L/C it ships under, the country's export
 *   permit (EXP in Bangladesh) and the discrepancies accepted on shipping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fac_lcs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('buyer_id');
            $table->string('number', 60);
            $table->date('issued_on');
            $table->string('issuing_bank', 150);
            $table->string('advising_bank', 150)->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            // Basis points either way (500 = ±5%).
            $table->unsignedSmallInteger('tolerance_bp')->default(0);
            $table->date('latest_ship_on');
            $table->date('expires_on');
            $table->boolean('partial_allowed')->default(true);
            $table->boolean('transshipment_allowed')->default(false);
            $table->string('port_loading', 100)->nullable();
            $table->string('port_discharge', 100)->nullable();
            // 0: at sight; else days after sight.
            $table->unsignedSmallInteger('payment_days')->default(0);
            $table->json('documents')->nullable();
            // open | closed
            $table->string('status', 10)->default('open');
            $table->string('note', 500)->nullable();
            $table->ulid('created_by');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'number'], 'fac_lcs_number_unique');
            $table->index(['organization_id', 'buyer_id'], 'fac_lcs_buyer_index');
        });

        Schema::create('fac_lc_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('lc_id');
            $table->ulid('order_id');
            $table->timestamps();

            $table->unique(['organization_id', 'order_id'], 'fac_lc_orders_order_unique');
            $table->index(['organization_id', 'lc_id'], 'fac_lc_orders_lc_index');
        });

        Schema::table('fac_shipments', function (Blueprint $table) {
            $table->ulid('lc_id')->nullable()->after('order_id');
            $table->string('export_permit_no', 60)->nullable()->after('destination');
            $table->date('export_permit_on')->nullable()->after('export_permit_no');
            $table->json('discrepancies')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('fac_shipments', function (Blueprint $table) {
            $table->dropColumn(['lc_id', 'export_permit_no', 'export_permit_on', 'discrepancies']);
        });
        Schema::dropIfExists('fac_lc_orders');
        Schema::dropIfExists('fac_lcs');
    }
};
