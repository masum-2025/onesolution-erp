<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FEE-1: student fees (module education_fees).
 *
 * - fee_heads: what a fee is for (tuition, admission, exam, transport…),
 *   how often it is billed (monthly, once a session, on admission, per
 *   credit, other), the income posting key it goes to, an optional sales
 *   tax code, and whether sibling discounts and late fines apply.
 * - fee_structures + fee_structure_lines: amounts per head for a session,
 *   narrowed by campus, programme, class and student category; the most
 *   specific structure that fits a student wins, head by head.
 * - fee_concessions: a student's discount or scholarship (percent in basis
 *   points or a fixed amount, one head or all), with a reason; above the
 *   rule's limit another person approves it.
 * - fee_runs: billing a month, a session's once-a-session fees or other
 *   heads for a campus, class or section: draft -> final (or cancelled).
 * - fee_bills + fee_bill_lines: one student's bill with its lines (amount,
 *   discount, tax, what is owed). A bill is never deleted: open, paid,
 *   cancelled (with a reason). One bill per student and billing key
 *   ("monthly:2026-03", "session:<id>", "admission", "other:<run>").
 * - fee_fines: late fines added to a bill over time and waivers (negative),
 *   append-only; the bill keeps the sum.
 * - fee_sequences: bill numbers counted per year.
 *
 * Money is integer minor units in the institution's currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_heads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('code', 20);
            $table->json('name');
            // monthly | session | admission | per_credit | other
            $table->string('frequency', 12);
            $table->string('income_key', 60);
            $table->ulid('tax_code_id')->nullable();
            $table->boolean('sibling_discount')->default(false);
            $table->boolean('late_fine')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'fee_heads_code_unique');
        });

        Schema::create('fee_structures', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('name', 150);
            $table->ulid('session_id');
            // Empty: every campus, programme, class or category.
            $table->ulid('unit_id')->nullable();
            $table->ulid('program_id')->nullable();
            $table->ulid('level_id')->nullable();
            $table->ulid('category_id')->nullable();
            // draft | active | archived
            $table->string('status', 10)->default('draft');
            $table->ulid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['organization_id', 'session_id', 'status'], 'fee_structures_session_index');
        });

        Schema::create('fee_structure_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('structure_id');
            $table->ulid('head_id');
            $table->bigInteger('amount_minor');
            // Months (1-12) a monthly head is billed in; empty: every month.
            $table->json('months')->nullable();
            $table->timestamps();

            $table->unique(['structure_id', 'head_id'], 'fee_structure_lines_head_unique');
            $table->index(['organization_id', 'structure_id']);
        });

        Schema::create('fee_concessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('student_id');
            // Empty: every head that allows discounts.
            $table->ulid('head_id')->nullable();
            // percent | fixed
            $table->string('mode', 8);
            $table->unsignedSmallInteger('percent_bp')->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->string('reason', 300);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            // pending | active | rejected | ended
            $table->string('status', 10);
            $table->ulid('requested_by')->nullable();
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['organization_id', 'student_id', 'status'], 'fee_concessions_student_index');
        });

        Schema::create('fee_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('session_id');
            // monthly | session | other
            $table->string('kind', 10);
            // "2026-03" for a month; empty otherwise.
            $table->string('period', 7)->nullable();
            $table->ulid('level_id')->nullable();
            $table->ulid('section_id')->nullable();
            // The heads an "other" run bills.
            $table->json('head_ids')->nullable();
            $table->date('issue_date');
            $table->date('due_date');
            // draft | final | cancelled
            $table->string('status', 10)->default('draft');
            $table->unsignedInteger('bills_count')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->string('note', 300)->nullable();
            $table->ulid('created_by')->nullable();
            $table->ulid('finalized_by')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['organization_id', 'session_id', 'status'], 'fee_runs_session_index');
            $table->unique(['organization_id', 'op_id'], 'fee_runs_op_unique');
        });

        Schema::create('fee_bills', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('student_id');
            $table->ulid('session_id');
            $table->ulid('run_id')->nullable();
            $table->string('billing_key', 40);
            $table->string('number', 40)->nullable();
            $table->date('issue_date');
            $table->date('due_date');
            // draft | open | paid | cancelled
            $table->string('status', 10)->default('draft');
            $table->char('currency', 3);
            $table->bigInteger('gross_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('fine_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('paid_minor')->default(0);
            $table->timestamp('fines_stopped_at')->nullable();
            // run | admission | manual
            $table->string('source', 10);
            $table->string('cancel_reason', 300)->nullable();
            $table->ulid('journal_id')->nullable();
            $table->ulid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            // "<student>:<billing key>" while the bill stands; emptied when it is cancelled, so it can be billed again.
            $table->string('active_key', 80)->nullable();
            $table->unique(['organization_id', 'active_key'], 'fee_bills_active_key_unique');
            $table->unique(['organization_id', 'number'], 'fee_bills_number_unique');
            $table->index(['organization_id', 'student_id', 'status'], 'fee_bills_student_index');
            $table->index(['organization_id', 'run_id']);
            $table->index(['organization_id', 'status', 'due_date'], 'fee_bills_due_index');
        });

        Schema::create('fee_bill_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('bill_id');
            $table->ulid('head_id');
            $table->bigInteger('amount_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            // What the family owes for the line: amount - discount (+ tax when prices exclude it).
            $table->bigInteger('due_minor');
            $table->ulid('tax_code_id')->nullable();
            // How the discount was found: concessions and the sibling discount.
            $table->json('basis')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'bill_id']);
        });

        Schema::create('fee_fines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('bill_id');
            // fine | waiver
            $table->string('kind', 8);
            $table->bigInteger('amount_minor');
            $table->date('applied_on');
            $table->string('reason', 300)->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['bill_id', 'kind', 'applied_on'], 'fee_fines_day_unique');
            $table->index(['organization_id', 'bill_id']);
        });

        Schema::create('fee_sequences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('kind', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'kind', 'year'], 'fee_sequences_unique');
        });
    }

    public function down(): void
    {
        foreach (['fee_sequences', 'fee_fines', 'fee_bill_lines', 'fee_bills', 'fee_runs', 'fee_concessions', 'fee_structure_lines', 'fee_structures', 'fee_heads'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
