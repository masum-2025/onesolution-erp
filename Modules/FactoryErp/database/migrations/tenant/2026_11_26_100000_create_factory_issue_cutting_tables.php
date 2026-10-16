<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * FAC-2a, in the client's database:
 * - fac_issues + fac_issue_lines: materials going from a store to the floor
 *   for an order (issue), or coming back from it (return), with their cost
 *   (minor units of the company's currency) as Inventory valued them.
 *   Append-only: a mistake is put right by a return.
 * - fac_cuttings + fac_cutting_lines: one lay cut for an order, pieces per
 *   size and colour. Not money: a wrong one is voided with a reason, never
 *   deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fac_issues', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('order_id');
            $table->ulid('warehouse_id');
            // issue | return
            $table->string('kind', 10);
            $table->date('issued_on');
            $table->bigInteger('cost_minor')->default(0);
            $table->char('currency', 3);
            $table->ulid('journal_id')->nullable();
            $table->string('note', 300)->nullable();
            $table->ulid('created_by');
            $table->string('op_id', 64)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'op_id'], 'fac_issues_op_unique');
            $table->index(['organization_id', 'order_id'], 'fac_issues_order_index');
        });

        Schema::create('fac_issue_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('issue_id');
            $table->ulid('order_id');
            $table->ulid('item_id');
            // Always above zero; the issue's kind says which way it went.
            $table->unsignedBigInteger('quantity_milli');
            $table->unsignedBigInteger('cost_minor')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'order_id', 'item_id'], 'fac_issue_lines_order_index');
            $table->index(['organization_id', 'issue_id'], 'fac_issue_lines_issue_index');
        });

        Schema::create('fac_cuttings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('order_id');
            $table->unsignedInteger('lay_number');
            $table->date('cut_on');
            $table->unsignedInteger('pieces')->default(0);
            $table->string('note', 300)->nullable();
            $table->ulid('created_by');
            $table->timestamp('voided_at')->nullable();
            $table->ulid('voided_by')->nullable();
            $table->string('void_reason', 300)->nullable();
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id'], 'fac_cuttings_op_unique');
            $table->unique(['organization_id', 'order_id', 'lay_number'], 'fac_cuttings_lay_unique');
        });

        Schema::create('fac_cutting_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('cutting_id');
            $table->ulid('order_id');
            $table->string('size', 20);
            $table->string('color', 40);
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->index(['organization_id', 'order_id'], 'fac_cutting_lines_order_index');
            $table->index(['organization_id', 'cutting_id'], 'fac_cutting_lines_cutting_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fac_cutting_lines');
        Schema::dropIfExists('fac_cuttings');
        Schema::dropIfExists('fac_issue_lines');
        Schema::dropIfExists('fac_issues');
    }
};
