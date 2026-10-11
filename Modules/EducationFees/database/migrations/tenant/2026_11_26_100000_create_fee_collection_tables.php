<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FEE-2a: collecting fees (module education_fees). All money rows are
 * append-only: a receipt is voided, never changed; what it paid is undone
 * by new negative rows.
 *
 * - fee_receipts: money taken from a student's family (cash, bank, mobile,
 *   online), numbered; valid or voided.
 * - fee_allocations: how much of a receipt (or of the student's advance)
 *   went to which bill; a void or a cancelled bill adds negative rows
 *   pointing at the row they undo. A bill's paid amount is their sum.
 * - fee_advances: the student's money held for later (more paid than owed,
 *   a paid bill cancelled, fewer credits): positive rows in, negative rows
 *   out (used on a bill, refunded, its receipt voided). The balance is the sum.
 * - fee_voids: asking to void a receipt and another person's decision.
 * - fee_refunds: paying a student's advance back, asked and decided.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_receipts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('student_id');
            $table->string('number', 40);
            $table->date('received_on');
            // cash | bank | mobile | online
            $table->string('method', 10);
            $table->string('reference', 100)->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            // valid | voided
            $table->string('status', 8)->default('valid');
            $table->string('note', 300)->nullable();
            $table->ulid('collected_by')->nullable();
            // The online payment it came from (FEE-2b).
            $table->ulid('payment_id')->nullable();
            $table->ulid('journal_id')->nullable();
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'number'], 'fee_receipts_number_unique');
            $table->unique(['organization_id', 'op_id'], 'fee_receipts_op_unique');
            $table->index(['organization_id', 'student_id']);
            $table->index(['organization_id', 'received_on', 'collected_by'], 'fee_receipts_day_index');
        });

        Schema::create('fee_allocations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('bill_id');
            // receipt | advance | reversal
            $table->string('kind', 10);
            $table->ulid('receipt_id')->nullable();
            $table->ulid('advance_id')->nullable();
            $table->ulid('reverses_id')->nullable();
            $table->bigInteger('amount_minor');
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'bill_id']);
            $table->index(['organization_id', 'receipt_id']);
        });

        Schema::create('fee_advances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('student_id');
            // overpaid | bill_cancelled | credits_reduced | applied | refunded | voided
            $table->string('kind', 16);
            $table->bigInteger('amount_minor');
            $table->ulid('receipt_id')->nullable();
            $table->ulid('bill_id')->nullable();
            $table->ulid('refund_id')->nullable();
            $table->string('note', 300)->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'student_id']);
            $table->index(['organization_id', 'receipt_id']);
        });

        Schema::create('fee_voids', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('receipt_id');
            $table->string('reason', 300);
            // pending | approved | rejected
            $table->string('status', 10);
            $table->ulid('requested_by')->nullable();
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'receipt_id']);
        });

        Schema::create('fee_refunds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('student_id');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            // cash | bank | mobile
            $table->string('method', 10);
            $table->string('reference', 100)->nullable();
            $table->string('reason', 300);
            // pending | approved | rejected
            $table->string('status', 10);
            $table->ulid('requested_by')->nullable();
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->ulid('journal_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['organization_id', 'student_id']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['fee_refunds', 'fee_voids', 'fee_advances', 'fee_allocations', 'fee_receipts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
