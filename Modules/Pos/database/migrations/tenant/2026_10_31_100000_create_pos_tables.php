<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS-1: point of sale (in the client's database).
 *
 * organization_id is the company on every table; a counter belongs to a
 * branch (unit_id) and sells out of one Inventory warehouse (an id only).
 * Money is integer minor units; quantities integer thousandths.
 *
 * - pos_registers: counters (tills) and the payment methods they take.
 * - pos_sessions: a shift at a counter: opened with a float, closed with the
 *   cash counted; a difference beyond the rule waits for a supervisor.
 * - pos_sales + pos_sale_lines + pos_payments: sales and returns (a return
 *   points at its sale), never changed once made; prices, tax and cost are
 *   kept as they were at the time.
 * - pos_sequences: receipt numbers per counter and year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_registers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            // Inventory's warehouse the counter sells out of.
            $table->ulid('warehouse_id');
            $table->string('code', 12);
            $table->json('name');
            // cash, card, mobile
            $table->json('payment_methods');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('pos_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('register_id')->constrained('pos_registers')->restrictOnDelete();
            // open, pending_review, closed
            $table->string('status', 20);
            $table->ulid('opened_by');
            $table->timestamp('opened_at');
            $table->unsignedBigInteger('opening_float_minor')->default(0);
            $table->ulid('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->bigInteger('expected_cash_minor')->nullable();
            $table->unsignedBigInteger('counted_cash_minor')->nullable();
            $table->bigInteger('variance_minor')->nullable();
            $table->string('close_note', 500)->nullable();
            $table->ulid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->unsignedInteger('sales_count')->default(0);
            $table->unsignedBigInteger('sales_minor')->default(0);
            $table->unsignedBigInteger('returns_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->char('currency_code', 3);
            $table->ulid('variance_journal_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['register_id', 'status']);
        });

        Schema::create('pos_sales', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('register_id')->constrained('pos_registers')->restrictOnDelete();
            $table->foreignUlid('session_id')->constrained('pos_sessions')->restrictOnDelete();
            $table->ulid('unit_id');
            $table->string('number', 40);
            // sale, return
            $table->string('kind', 10);
            $table->ulid('original_sale_id')->nullable();
            $table->timestamp('sold_at');
            $table->string('customer_name', 150)->nullable();
            $table->string('reason', 300)->nullable();
            // Before discount; then discount, tax, what is owed, what was paid, the change.
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('paid_minor');
            $table->unsignedBigInteger('change_minor')->default(0);
            $table->unsignedBigInteger('cost_minor')->default(0);
            $table->char('currency_code', 3);
            $table->boolean('prices_include_tax');
            $table->boolean('offline')->default(false);
            // Made offline and something did not hold (stock below zero, a closed shift, a discount over the limit).
            $table->string('review_reason', 40)->nullable();
            $table->ulid('created_by');
            $table->ulid('journal_id')->nullable();
            $table->string('op_id', 64);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id']);
            $table->unique(['organization_id', 'number']);
            $table->index(['session_id', 'kind']);
            $table->index(['organization_id', 'sold_at']);
        });

        Schema::create('pos_sale_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('sale_id')->constrained('pos_sales')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->ulid('item_id');
            $table->string('sku', 40);
            $table->json('name');
            $table->unsignedBigInteger('quantity_milli');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            // Without tax; with tax (what the customer pays for the line).
            $table->unsignedBigInteger('net_minor');
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('cost_minor')->default(0);
            // A return's line points at the sale's; a sale's line counts what came back.
            $table->ulid('original_line_id')->nullable();
            $table->unsignedBigInteger('returned_milli')->default(0);

            $table->index(['sale_id', 'line_no']);
        });

        Schema::create('pos_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('sale_id')->constrained('pos_sales')->restrictOnDelete();
            // cash, card, mobile
            $table->string('method', 20);
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference', 80)->nullable();

            $table->index('sale_id');
        });

        Schema::create('pos_sequences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('register_id');
            $table->string('kind', 10);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('next')->default(1);

            $table->unique(['register_id', 'kind', 'year']);
        });
    }

    public function down(): void
    {
        foreach (['pos_sequences', 'pos_payments', 'pos_sale_lines', 'pos_sales', 'pos_sessions', 'pos_registers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
