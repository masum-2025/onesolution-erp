<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FAC-1: a garments factory's styles, their bill of materials, and buyers' orders.
 *
 * - fac_styles: a buyer's style (the buyer is a CRM organization contact),
 *   its sizes and colours in order, and whether its consumption is written
 *   per piece or per dozen (kept from the rule when the style is made).
 * - fac_bom_lines: what a style is made of: an Inventory item, how much per
 *   piece or dozen (thousandths of the item's unit), wastage (basis
 *   points), for every piece or only one size and/or colour.
 * - fac_orders + fac_order_lines: a buyer's order (PO) for a style: ship
 *   date, price per piece (minor units of its currency), and pieces per
 *   size and colour. Numbered per year (fac_sequences).
 * - fac_fields: the company's own fields on styles and orders (values in
 *   their extra column, checked on the way in); key and type fixed once
 *   made, switched off rather than removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fac_styles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('buyer_id');
            $table->string('code', 40);
            $table->string('name', 150);
            $table->string('product_type', 40)->nullable();
            $table->json('sizes');
            $table->json('colors');
            $table->string('consumption_basis', 10);
            $table->text('note')->nullable();
            $table->json('extra')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'fac_styles_code_unique');
            $table->index(['organization_id', 'buyer_id'], 'fac_styles_buyer_index');
        });

        Schema::create('fac_bom_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('style_id');
            $table->ulid('item_id');
            $table->unsignedBigInteger('consumption_milli');
            $table->unsignedInteger('wastage_bp')->default(0);
            // Only for this size and/or colour (null: every piece).
            $table->string('size', 20)->nullable();
            $table->string('color', 40)->nullable();
            $table->string('note', 200)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'style_id'], 'fac_bom_style_index');
        });

        Schema::create('fac_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // The factory (branch) the order is made at.
            $table->ulid('unit_id');
            $table->string('number', 30);
            $table->ulid('buyer_id');
            $table->ulid('style_id');
            $table->string('po_number', 60)->nullable();
            $table->date('ship_on');
            $table->char('currency', 3);
            $table->unsignedBigInteger('unit_price_minor');
            // draft | confirmed | in_production | shipped | cancelled
            $table->string('status', 20)->default('draft');
            $table->text('note')->nullable();
            $table->json('extra')->nullable();
            $table->string('cancelled_reason', 300)->nullable();
            $table->ulid('created_by');
            $table->timestamp('confirmed_at')->nullable();
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'number'], 'fac_orders_number_unique');
            $table->unique(['organization_id', 'op_id'], 'fac_orders_op_unique');
            $table->index(['organization_id', 'unit_id', 'status', 'ship_on'], 'fac_orders_unit_index');
            $table->index(['organization_id', 'style_id'], 'fac_orders_style_index');
        });

        Schema::create('fac_order_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('order_id');
            $table->string('size', 20);
            $table->string('color', 40);
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['organization_id', 'order_id', 'size', 'color'], 'fac_order_lines_cell_unique');
        });

        Schema::create('fac_fields', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // style | order
            $table->string('entity', 12);
            $table->string('key', 40);
            $table->json('label');
            // text | long_text | number | money | date | choice | yes_no
            $table->string('type', 12);
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            // Shown on printed order sheets (later).
            $table->boolean('on_print')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'entity', 'key'], 'fac_fields_key_unique');
        });

        Schema::create('fac_sequences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('kind', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('next');
            $table->timestamps();

            $table->unique(['organization_id', 'kind', 'year'], 'fac_sequences_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fac_sequences');
        Schema::dropIfExists('fac_fields');
        Schema::dropIfExists('fac_order_lines');
        Schema::dropIfExists('fac_orders');
        Schema::dropIfExists('fac_bom_lines');
        Schema::dropIfExists('fac_styles');
    }
};
