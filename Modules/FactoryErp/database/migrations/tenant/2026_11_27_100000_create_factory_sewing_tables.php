<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * FAC-2b, in the client's database:
 * - fac_styles.finished_item_id: the Inventory item finished pieces of the
 *   style go into the store as (sizes and colours stay in the factory).
 * - fac_lines: sewing lines of a factory (branch).
 * - fac_outputs + fac_output_lines: what a line sewed for an order in an
 *   hour (or a day), good pieces and rejects per size and colour. Not
 *   money: a wrong entry is voided with a reason, never deleted.
 * - fac_receipts + fac_receipt_lines: finished pieces of an order into a
 *   store, at their share of the order's work in progress (minor units).
 *   Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fac_styles', function (Blueprint $table) {
            $table->ulid('finished_item_id')->nullable()->after('consumption_basis');
        });

        Schema::create('fac_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->string('code', 20);
            $table->string('name', 100);
            $table->unsignedSmallInteger('machines')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'fac_lines_code_unique');
            $table->index(['organization_id', 'unit_id'], 'fac_lines_unit_index');
        });

        Schema::create('fac_outputs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('line_id');
            $table->ulid('order_id');
            $table->date('worked_on');
            // 0-23 at the company; null when the company writes a day at a time.
            $table->unsignedTinyInteger('hour')->nullable();
            $table->unsignedInteger('good')->default(0);
            $table->unsignedInteger('rejects')->default(0);
            $table->ulid('created_by');
            $table->timestamp('voided_at')->nullable();
            $table->ulid('voided_by')->nullable();
            $table->string('void_reason', 300)->nullable();
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id'], 'fac_outputs_op_unique');
            $table->index(['organization_id', 'order_id'], 'fac_outputs_order_index');
            $table->index(['organization_id', 'worked_on', 'line_id'], 'fac_outputs_day_index');
        });

        Schema::create('fac_output_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('output_id');
            $table->ulid('order_id');
            $table->string('size', 20);
            $table->string('color', 40);
            $table->unsignedInteger('good')->default(0);
            $table->unsignedInteger('rejects')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'order_id'], 'fac_output_lines_order_index');
            $table->index(['organization_id', 'output_id'], 'fac_output_lines_output_index');
        });

        Schema::create('fac_receipts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('order_id');
            $table->ulid('warehouse_id');
            $table->ulid('item_id');
            $table->date('received_on');
            $table->unsignedInteger('pieces');
            // The last one takes all the work in progress left.
            $table->boolean('is_last')->default(false);
            $table->unsignedBigInteger('cost_minor')->default(0);
            $table->char('currency', 3);
            $table->ulid('journal_id')->nullable();
            $table->string('note', 300)->nullable();
            $table->ulid('created_by');
            $table->string('op_id', 64)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'op_id'], 'fac_receipts_op_unique');
            $table->index(['organization_id', 'order_id'], 'fac_receipts_order_index');
        });

        Schema::create('fac_receipt_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('receipt_id');
            $table->ulid('order_id');
            $table->string('size', 20);
            $table->string('color', 40);
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->index(['organization_id', 'order_id'], 'fac_receipt_lines_order_index');
            $table->index(['organization_id', 'receipt_id'], 'fac_receipt_lines_receipt_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fac_receipt_lines');
        Schema::dropIfExists('fac_receipts');
        Schema::dropIfExists('fac_output_lines');
        Schema::dropIfExists('fac_outputs');
        Schema::dropIfExists('fac_lines');
        Schema::table('fac_styles', function (Blueprint $table) {
            $table->dropColumn('finished_item_id');
        });
    }
};
