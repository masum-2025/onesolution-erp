<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Advances and per-diem (EXP-3). Money is in minor units with the currency.
 *
 * - exp_advances: money an employee asks for before spending it, approved
 *   like a claim, paid in cash or by bank, then settled by claims, by
 *   handing back what is left, or from a month's salary.
 * - exp_advance_moves: what settled an advance (append-only): paid, a
 *   claim, money handed back, the salary.
 * - exp_advance_approvals: each decision on an advance's step.
 * - exp_claims.advance_id / advance_minor: the advance a claim settles and
 *   how much of the claim it took.
 * - exp_lines.per_diem_code / days: a daily allowance line (days at a rate
 *   of the rule expenses.per_diem_rates); exp_categories.per_diem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exp_advances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('employee_id')->index();
            $table->string('number', 30)->nullable();
            $table->string('purpose', 300);
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->date('settle_by')->nullable();
            $table->string('status', 20);
            $table->unsignedTinyInteger('steps_needed')->default(1);
            $table->unsignedTinyInteger('steps_done')->default(0);
            $table->ulid('requested_by');
            $table->timestamp('decided_at')->nullable();
            $table->ulid('decided_by')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->string('pay_method', 10)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->ulid('paid_by')->nullable();
            $table->ulid('journal_id')->nullable();
            $table->char('recover_period', 7)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version');
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->unique(['organization_id', 'op_id']);
        });

        Schema::create('exp_advance_moves', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('advance_id')->index();
            $table->string('kind', 20);
            $table->unsignedBigInteger('amount_minor');
            $table->ulid('claim_id')->nullable();
            $table->string('method', 10)->nullable();
            $table->ulid('run_id')->nullable();
            $table->ulid('journal_id')->nullable();
            $table->ulid('user_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('exp_advance_approvals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('advance_id')->index();
            $table->unsignedTinyInteger('step');
            $table->ulid('user_id');
            $table->string('decision', 20);
            $table->string('note', 500)->nullable();
            $table->timestamps();
        });

        Schema::table('exp_claims', function (Blueprint $table) {
            $table->ulid('advance_id')->nullable()->index();
            $table->unsignedBigInteger('advance_minor')->default(0);
        });
        Schema::table('exp_lines', function (Blueprint $table) {
            $table->string('per_diem_code', 20)->nullable();
            $table->unsignedSmallInteger('days')->nullable();
        });
        Schema::table('exp_categories', function (Blueprint $table) {
            $table->boolean('per_diem')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('exp_categories', function (Blueprint $table) {
            $table->dropColumn('per_diem');
        });
        Schema::table('exp_lines', function (Blueprint $table) {
            $table->dropColumn(['per_diem_code', 'days']);
        });
        Schema::table('exp_claims', function (Blueprint $table) {
            $table->dropIndex(['advance_id']);
            $table->dropColumn(['advance_id', 'advance_minor']);
        });
        Schema::dropIfExists('exp_advance_approvals');
        Schema::dropIfExists('exp_advance_moves');
        Schema::dropIfExists('exp_advances');
    }
};
