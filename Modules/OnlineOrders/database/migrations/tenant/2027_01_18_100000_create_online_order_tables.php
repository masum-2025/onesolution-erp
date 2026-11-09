<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OO-1: online orders (in the client's database).
 *
 * organization_id is the company on every table; an order belongs to a
 * branch (unit_id) and ships out of one Inventory warehouse (an id only).
 * Money is integer minor units; quantities integer thousandths.
 *
 * - online_orders: an order from a channel (typed in by staff, later
 *   Facebook, the website): customer, address, delivery, totals frozen when
 *   made, where it stands (draft → confirmed → packed → shipped → delivered,
 *   or cancelled / returned).
 * - online_order_lines: items as they were priced, and the cost they left at.
 * - online_order_payments: money in (payment) and back (refund), append-only.
 * - online_order_events: every change of where an order stands, append-only.
 * - online_order_sequences: order numbers per year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            // Inventory's warehouse the order ships out of.
            $table->ulid('warehouse_id');
            $table->string('number', 40);
            // manual, facebook, website
            $table->string('channel', 20);
            // The channel's own reference (a conversation, a cart), never personal data.
            $table->string('channel_ref', 120)->nullable();
            // draft, confirmed, packed, shipped, delivered, cancelled, returned
            $table->string('status', 20);
            $table->string('customer_name', 150);
            $table->string('customer_phone', 20);
            $table->ulid('customer_id')->nullable();
            $table->string('address', 500);
            $table->string('delivery_zone', 20)->nullable();
            // cod, prepaid
            $table->string('payment_method', 10);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('delivery_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('paid_minor')->default(0);
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->unsignedBigInteger('cost_minor')->default(0);
            $table->char('currency_code', 3);
            $table->boolean('prices_include_tax');
            $table->string('note', 500)->nullable();
            $table->string('courier', 80)->nullable();
            $table->string('tracking', 80)->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->ulid('delivery_journal_id')->nullable();
            $table->ulid('return_journal_id')->nullable();
            $table->ulid('created_by');
            $table->string('op_id', 64);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id']);
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
            $table->index(['warehouse_id', 'status']);
            $table->index(['organization_id', 'customer_phone']);
        });

        Schema::create('online_order_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('order_id')->constrained('online_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->ulid('item_id');
            $table->string('sku', 40);
            $table->json('name');
            $table->boolean('keeps_stock')->default(true);
            $table->unsignedBigInteger('quantity_milli');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('net_minor');
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('cost_minor')->default(0);

            $table->index(['order_id', 'line_no']);
            $table->index(['organization_id', 'item_id']);
        });

        Schema::create('online_order_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('order_id')->constrained('online_orders')->restrictOnDelete();
            // payment, refund
            $table->string('kind', 10);
            // cash, mobile, bank
            $table->string('method', 10);
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference', 80)->nullable();
            $table->ulid('journal_id')->nullable();
            $table->ulid('recorded_by');
            $table->string('op_id', 64);
            $table->timestamp('created_at');

            $table->unique(['organization_id', 'op_id']);
            $table->index('order_id');
        });

        Schema::create('online_order_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('order_id')->constrained('online_orders')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('note', 300)->nullable();
            $table->ulid('actor_id')->nullable();
            $table->timestamp('created_at');

            $table->index('order_id');
        });

        Schema::create('online_order_sequences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('next');

            $table->unique(['organization_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_order_sequences');
        Schema::dropIfExists('online_order_events');
        Schema::dropIfExists('online_order_payments');
        Schema::dropIfExists('online_order_lines');
        Schema::dropIfExists('online_orders');
    }
};
