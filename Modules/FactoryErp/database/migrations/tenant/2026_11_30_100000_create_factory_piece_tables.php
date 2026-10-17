<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * FAC-3c, in the client's database:
 * - fac_piece_tickets: what an operator (an HRM employee) did on a day:
 *   pieces of one operation of an order, at the operation's piece rate
 *   then (kept here, so a later rate change leaves it), and the amount
 *   (minor units of the company's currency) Payroll adds to their pay.
 *   Pay: append-only, a mistake is voided with a reason.
 * - fac_pay_locks: months whose payroll was approved; their tickets no
 *   longer change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fac_piece_tickets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('employee_id');
            $table->ulid('order_id');
            $table->ulid('operation_id');
            $table->string('operation_name', 120);
            $table->date('worked_on');
            $table->unsignedInteger('pieces');
            $table->unsignedBigInteger('rate_minor');
            // 1: the rate is per piece; 12: per dozen.
            $table->unsignedTinyInteger('rate_per');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->ulid('created_by');
            $table->timestamp('voided_at')->nullable();
            $table->ulid('voided_by')->nullable();
            $table->string('void_reason', 300)->nullable();
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id'], 'fac_piece_tickets_op_unique');
            $table->index(['organization_id', 'employee_id', 'worked_on'], 'fac_piece_tickets_employee_index');
            $table->index(['organization_id', 'order_id', 'operation_id'], 'fac_piece_tickets_operation_index');
            $table->index(['organization_id', 'worked_on'], 'fac_piece_tickets_day_index');
        });

        Schema::create('fac_pay_locks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // "2026-11"
            $table->char('period', 7);
            $table->timestamps();

            $table->unique(['organization_id', 'period'], 'fac_pay_locks_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fac_pay_locks');
        Schema::dropIfExists('fac_piece_tickets');
    }
};
