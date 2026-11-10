<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Pharmacy's own records, in the client's database. Stock, batches and expiry
 * stay Inventory's; money stays POS's (a sale is a POS sale). Here:
 *
 * - pharmacy_medicines: what makes an Inventory item a medicine (generic,
 *   strength, form, DGDA no., MRP of one piece, pieces per strip, strips per box).
 * - pharmacy_sale_links: a POS sale made at the pharmacy counter and its
 *   prescription (who prescribed, BMDC no., for whom).
 * - pharmacy_purchases: a medicine purchase (an Inventory receipt) as the pharmacy made it.
 * - pharmacy_supplier_returns (+ lines): expiring stock going back to a
 *   supplier; approved by another person (maker-checker), then an Inventory issue.
 * - pharmacy_controlled_entries: the controlled-drug register, append-only:
 *   every in and out with its batch, the balance after, and for whom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_medicines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('item_id');
            $table->ulid('generic_id');
            $table->ulid('manufacturer_id')->nullable();
            $table->string('strength', 40)->nullable();
            $table->string('form', 20);
            $table->string('dgda_no', 40)->nullable();
            $table->unsignedBigInteger('mrp_minor');
            $table->char('currency', 3);
            $table->unsignedInteger('pieces_per_strip')->nullable();
            $table->unsignedInteger('strips_per_box')->nullable();
            $table->boolean('requires_prescription')->default(false);
            $table->boolean('is_active')->default(true);
            $table->ulid('created_by');
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'item_id'], 'pharmacy_medicines_item_unique');
            $table->unique(['organization_id', 'op_id'], 'pharmacy_medicines_op_unique');
            $table->index(['organization_id', 'generic_id'], 'pharmacy_medicines_generic_index');
        });

        Schema::create('pharmacy_sale_links', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('pos_sale_id');
            $table->string('sale_number', 40);
            $table->unsignedBigInteger('total_minor');
            $table->char('currency', 3);
            $table->timestamp('sold_at');
            $table->string('patient_name', 120)->nullable();
            $table->string('prescription_ref', 60)->nullable();
            $table->string('prescriber_name', 120)->nullable();
            $table->string('prescriber_bmdc', 20)->nullable();
            $table->boolean('has_controlled')->default(false);
            $table->ulid('created_by');
            $table->string('op_id', 64);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id'], 'pharmacy_sale_links_op_unique');
            $table->unique(['organization_id', 'pos_sale_id'], 'pharmacy_sale_links_sale_unique');
            $table->index(['organization_id', 'unit_id', 'sold_at'], 'pharmacy_sale_links_day_index');
        });

        Schema::create('pharmacy_purchases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('document_id');
            $table->string('document_number', 40)->nullable();
            $table->ulid('warehouse_id');
            $table->string('supplier', 150);
            $table->string('reference', 80)->nullable();
            $table->date('purchased_on');
            $table->unsignedBigInteger('total_minor');
            $table->char('currency', 3);
            $table->unsignedInteger('line_count');
            $table->ulid('created_by');
            $table->string('op_id', 64);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id'], 'pharmacy_purchases_op_unique');
            $table->index(['organization_id', 'unit_id', 'purchased_on'], 'pharmacy_purchases_day_index');
        });

        Schema::create('pharmacy_supplier_returns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->string('number', 40)->nullable();
            $table->ulid('warehouse_id');
            $table->string('supplier', 150);
            $table->string('reason', 300);
            $table->string('status', 20);
            $table->ulid('document_id')->nullable();
            $table->ulid('created_by');
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('op_id', 64);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id'], 'pharmacy_supplier_returns_op_unique');
            $table->index(['organization_id', 'unit_id', 'status'], 'pharmacy_supplier_returns_status_index');
        });

        Schema::create('pharmacy_supplier_return_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('return_id');
            $table->ulid('medicine_id');
            $table->string('batch_number', 60);
            $table->date('expires_on')->nullable();
            $table->bigInteger('quantity_milli');
            $table->timestamps();

            $table->index(['organization_id', 'return_id'], 'pharmacy_supplier_return_lines_return_index');
        });

        Schema::create('pharmacy_controlled_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('medicine_id');
            $table->string('batch_number', 60)->nullable();
            $table->string('direction', 3);
            $table->bigInteger('quantity_milli');
            $table->bigInteger('balance_after_milli');
            $table->string('source_type', 20);
            $table->ulid('source_id');
            $table->string('patient_name', 120)->nullable();
            $table->string('prescriber_bmdc', 20)->nullable();
            $table->string('note', 255)->nullable();
            $table->ulid('recorded_by');
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['organization_id', 'medicine_id', 'recorded_at'], 'pharmacy_controlled_entries_medicine_index');
        });
    }

    public function down(): void
    {
        foreach (['pharmacy_controlled_entries', 'pharmacy_supplier_return_lines', 'pharmacy_supplier_returns', 'pharmacy_purchases', 'pharmacy_sale_links', 'pharmacy_medicines'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
