<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * FAC-4, in the client's database:
 * - fac_shipments: finished goods of an order leaving for the buyer: how
 *   (sea, air, road), the vessel or flight, B/L or AWB, destination, the
 *   store they leave from, the buyer's currency and the exchange rate to
 *   the company's (millionths), whether it is the order's last. Draft,
 *   then shipped (stock out, cost of sales, a draft invoice in the books);
 *   a shipped one never changes.
 * - fac_shipment_cartons: the packing list: a run of carton numbers, each
 *   with pieces of one size and colour, weights in grams, size in mm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fac_shipments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('order_id');
            $table->string('number', 30);
            $table->date('ship_on');
            // sea | air | road
            $table->string('mode', 10);
            $table->string('carrier', 100)->nullable();
            $table->string('transport_ref', 60)->nullable();
            $table->string('destination', 120)->nullable();
            $table->ulid('warehouse_id');
            $table->char('currency', 3);
            // Company currency per one of the buyer's, in millionths (1 when they are the same: 1000000).
            $table->unsignedBigInteger('rate_micro');
            $table->boolean('is_last')->default(false);
            // draft | shipped
            $table->string('status', 10)->default('draft');
            $table->unsignedInteger('pieces')->default(0);
            $table->unsignedBigInteger('value_minor')->default(0);
            $table->unsignedBigInteger('cost_minor')->default(0);
            $table->ulid('invoice_id')->nullable();
            $table->ulid('journal_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->ulid('created_by');
            $table->timestamp('shipped_at')->nullable();
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'number'], 'fac_shipments_number_unique');
            $table->unique(['organization_id', 'op_id'], 'fac_shipments_op_unique');
            $table->index(['organization_id', 'order_id'], 'fac_shipments_order_index');
        });

        Schema::create('fac_shipment_cartons', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('shipment_id');
            $table->ulid('order_id');
            $table->unsignedInteger('from_no');
            $table->unsignedInteger('to_no');
            $table->string('size', 20);
            $table->string('color', 40);
            $table->unsignedInteger('pieces_each');
            $table->unsignedInteger('net_grams_each')->default(0);
            $table->unsignedInteger('gross_grams_each')->default(0);
            $table->unsignedSmallInteger('length_mm')->default(0);
            $table->unsignedSmallInteger('width_mm')->default(0);
            $table->unsignedSmallInteger('height_mm')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'shipment_id'], 'fac_shipment_cartons_index');
            $table->index(['organization_id', 'order_id'], 'fac_shipment_cartons_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fac_shipment_cartons');
        Schema::dropIfExists('fac_shipments');
    }
};
