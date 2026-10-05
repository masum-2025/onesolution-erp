<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INV-1: a company's stock (in the client's database).
 *
 * organization_id is the company on every table; a warehouse belongs to a
 * branch or department (unit_id). Quantities are integer thousandths
 * (quantity_milli: 1.5 kg = 1500); money is integer minor units of the
 * company's currency. No floats anywhere.
 *
 * - inv_units, inv_categories: units of measure and item groups.
 * - inv_items: what is kept or sold (stock items keep a balance; others
 *   are sold or bought without one).
 * - inv_warehouses: where stock is kept.
 * - inv_batches + inv_batch_stock: batches (number, expiry) and how much of
 *   each sits in each warehouse.
 * - inv_moves: every movement, append-only (signed quantity and value).
 * - inv_balances: quantity and value per item and warehouse, kept in step
 *   with the moves; the valuation method is fixed when a balance starts.
 * - inv_layers: what is left of each receipt, consumed oldest first (FIFO).
 * - inv_documents + inv_document_lines: receipts, issues, transfers and
 *   adjustments, from draft to posted.
 * - inv_counts + inv_count_lines: stock counts (expected, counted, variance).
 * - inv_sequences: document numbers per kind and year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_units', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('code', 12);
            $table->json('name');
            // How many decimals a quantity may have (0 = whole pieces, up to 3).
            $table->unsignedTinyInteger('decimals')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('inv_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('parent_id')->nullable();
            $table->string('code', 20);
            $table->json('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('inv_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('sku', 40);
            $table->string('barcode', 64)->nullable();
            $table->json('name');
            $table->ulid('category_id')->nullable();
            $table->foreignUlid('unit_id')->constrained('inv_units')->restrictOnDelete();
            // stock (keeps a balance) or non_stock (services, things not counted)
            $table->string('kind', 20);
            $table->boolean('track_batches')->default(false);
            $table->unsignedBigInteger('sale_price_minor')->nullable();
            // Accounting's tax code (an id only; Inventory never reads Accounting's tables).
            $table->ulid('tax_code_id')->nullable();
            $table->unsignedBigInteger('reorder_level_milli')->nullable();
            $table->unsignedBigInteger('reorder_quantity_milli')->nullable();
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'sku']);
            $table->unique(['organization_id', 'barcode']);
            $table->index(['organization_id', 'category_id']);
        });

        Schema::create('inv_warehouses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // The branch or department it belongs to.
            $table->ulid('unit_id');
            $table->string('code', 20);
            $table->json('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('inv_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('item_id')->constrained('inv_items')->restrictOnDelete();
            $table->string('number', 60);
            $table->date('expires_on')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'number']);
            $table->index(['organization_id', 'expires_on']);
        });

        Schema::create('inv_batch_stock', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('batch_id')->constrained('inv_batches')->restrictOnDelete();
            $table->foreignUlid('warehouse_id')->constrained('inv_warehouses')->restrictOnDelete();
            $table->bigInteger('quantity_milli')->default(0);

            $table->unique(['batch_id', 'warehouse_id']);
        });

        Schema::create('inv_moves', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('item_id')->constrained('inv_items')->restrictOnDelete();
            $table->foreignUlid('warehouse_id')->constrained('inv_warehouses')->restrictOnDelete();
            $table->ulid('batch_id')->nullable();
            // receipt, issue, transfer_out, transfer_in, adjustment, count, sale, return
            $table->string('kind', 20);
            $table->bigInteger('quantity_milli');
            $table->bigInteger('value_minor');
            $table->date('moved_on');
            // What caused it: an inventory document, or another module's record (e.g. a POS sale).
            $table->string('source_module', 30);
            $table->string('source_type', 30);
            $table->ulid('source_id');
            $table->string('note', 255)->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'item_id', 'moved_on']);
            $table->index(['source_module', 'source_id']);
        });

        Schema::create('inv_balances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('item_id')->constrained('inv_items')->restrictOnDelete();
            $table->foreignUlid('warehouse_id')->constrained('inv_warehouses')->restrictOnDelete();
            $table->bigInteger('quantity_milli')->default(0);
            $table->bigInteger('value_minor')->default(0);
            // Cost of one unit last known (used when the balance is at or below zero).
            $table->unsignedBigInteger('last_unit_cost_minor')->default(0);
            // weighted_average or FIFO: fixed when the balance starts.
            $table->string('method', 20);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['item_id', 'warehouse_id']);
        });

        Schema::create('inv_layers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('item_id')->constrained('inv_items')->restrictOnDelete();
            $table->foreignUlid('warehouse_id')->constrained('inv_warehouses')->restrictOnDelete();
            $table->ulid('move_id');
            $table->unsignedBigInteger('remaining_milli');
            $table->unsignedBigInteger('remaining_value_minor');
            $table->timestamp('created_at')->nullable();

            $table->index(['item_id', 'warehouse_id', 'remaining_milli']);
        });

        Schema::create('inv_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // receipt, issue, transfer, adjustment
            $table->string('type', 20);
            $table->string('number', 40)->nullable();
            // draft, pending_approval, in_transit, posted, cancelled
            $table->string('status', 20);
            $table->foreignUlid('warehouse_id')->constrained('inv_warehouses')->restrictOnDelete();
            $table->ulid('to_warehouse_id')->nullable();
            $table->date('document_date');
            // Who it came from or went to, as written (a supplier, a department).
            $table->string('counterparty', 150)->nullable();
            $table->string('reference', 80)->nullable();
            $table->string('reason', 300)->nullable();
            $table->bigInteger('value_minor')->default(0);
            $table->char('currency_code', 3);
            $table->ulid('created_by')->nullable();
            $table->ulid('submitted_by')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->ulid('posted_by')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->ulid('received_by')->nullable();
            $table->ulid('journal_id')->nullable();
            $table->ulid('receipt_journal_id')->nullable();
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id']);
            $table->index(['organization_id', 'type', 'status']);
        });

        Schema::create('inv_document_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('document_id')->constrained('inv_documents')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignUlid('item_id')->constrained('inv_items')->restrictOnDelete();
            // Signed for adjustments (more found or less); positive otherwise.
            $table->bigInteger('quantity_milli');
            // Transfers: what arrived (the rest is lost on the way).
            $table->unsignedBigInteger('received_milli')->nullable();
            // Receipts and adjustments up: the cost of one unit (null = current cost).
            $table->unsignedBigInteger('unit_cost_minor')->nullable();
            $table->string('batch_number', 60)->nullable();
            $table->date('expires_on')->nullable();
            // Worked out when posted.
            $table->bigInteger('value_minor')->default(0);
            $table->string('note', 255)->nullable();

            $table->index(['document_id', 'line_no']);
        });

        Schema::create('inv_counts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('number', 40)->nullable();
            $table->foreignUlid('warehouse_id')->constrained('inv_warehouses')->restrictOnDelete();
            $table->ulid('category_id')->nullable();
            // counting, pending_approval, posted, cancelled
            $table->string('status', 20);
            $table->date('counted_on');
            $table->bigInteger('variance_value_minor')->default(0);
            $table->char('currency_code', 3);
            $table->ulid('created_by')->nullable();
            $table->ulid('submitted_by')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->ulid('journal_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('inv_count_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('count_id')->constrained('inv_counts')->restrictOnDelete();
            $table->foreignUlid('item_id')->constrained('inv_items')->restrictOnDelete();
            $table->bigInteger('expected_milli');
            $table->bigInteger('counted_milli')->nullable();
            $table->bigInteger('value_minor')->default(0);

            $table->unique(['count_id', 'item_id']);
        });

        Schema::create('inv_sequences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('kind', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('next')->default(1);

            $table->unique(['organization_id', 'kind', 'year']);
        });
    }

    public function down(): void
    {
        foreach (['inv_sequences', 'inv_count_lines', 'inv_counts', 'inv_document_lines', 'inv_documents', 'inv_layers', 'inv_balances', 'inv_moves', 'inv_batch_stock', 'inv_batches', 'inv_warehouses', 'inv_items', 'inv_categories', 'inv_units'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
