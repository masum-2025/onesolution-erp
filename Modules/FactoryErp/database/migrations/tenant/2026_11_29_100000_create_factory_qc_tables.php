<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * FAC-3b, in the client's database:
 * - fac_defect_types: the company's own kinds of defect (name in each
 *   language); switched off, never removed.
 * - fac_inspections + fac_inspection_defects: an end-line check of a
 *   line's pieces (how many checked, defects by kind) or an order's final
 *   inspection (lot, sample and accept number from the AQL table, pass or
 *   fail). Voided with a reason, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fac_defect_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('code', 20);
            $table->json('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'fac_defect_types_code_unique');
        });

        Schema::create('fac_inspections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('order_id');
            $table->ulid('line_id')->nullable();
            // endline | final
            $table->string('kind', 10);
            $table->date('inspected_on');
            $table->unsignedTinyInteger('hour')->nullable();
            $table->unsignedInteger('checked');
            $table->unsignedInteger('defects')->default(0);
            // Final inspections: the lot, and from the AQL table the sample and the most defects accepted.
            $table->unsignedInteger('lot_size')->nullable();
            $table->string('aql', 5)->nullable();
            $table->unsignedSmallInteger('accept')->nullable();
            // pass | fail (final only)
            $table->string('result', 10)->nullable();
            $table->string('note', 300)->nullable();
            $table->ulid('created_by');
            $table->timestamp('voided_at')->nullable();
            $table->ulid('voided_by')->nullable();
            $table->string('void_reason', 300)->nullable();
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id'], 'fac_inspections_op_unique');
            $table->index(['organization_id', 'order_id', 'kind'], 'fac_inspections_order_index');
            $table->index(['organization_id', 'inspected_on'], 'fac_inspections_day_index');
        });

        Schema::create('fac_inspection_defects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('inspection_id');
            $table->ulid('defect_type_id');
            $table->unsignedInteger('count');
            $table->timestamps();

            $table->index(['organization_id', 'inspection_id'], 'fac_inspection_defects_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fac_inspection_defects');
        Schema::dropIfExists('fac_inspections');
        Schema::dropIfExists('fac_defect_types');
    }
};
