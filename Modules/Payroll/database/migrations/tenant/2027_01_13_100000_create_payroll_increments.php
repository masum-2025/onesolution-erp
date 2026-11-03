<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * PAY-4: salary increments, usually after an appraisal cycle.
 *
 * - pay_increment_rounds: one round ("Yearly increment 2027", from a day):
 *   draft, waiting for approval, approved, applied (the new basics set as
 *   salaries from that day), or cancelled. Optionally the appraisal cycle
 *   whose bands suggest each rate.
 * - pay_increment_lines: one employee: the basic now, the band, the rate
 *   (basis points, 1000 = 10%) from the rule or set by hand, the new basic,
 *   left out or not, and once applied the salary it became (or why not).
 * - pay_increment_approvals: who approved at which level (each a
 *   different person; payroll.salary_approval_levels).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_increment_rounds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->json('title');
            // The appraisal cycle whose bands suggest the rates (a PerformanceSource's id), or none.
            $table->ulid('cycle_id')->nullable();
            $table->date('effective_from');
            // draft, pending_approval, approved, applied, cancelled
            $table->string('status', 20);
            $table->char('currency_code', 3);
            $table->unsignedInteger('employees')->default(0);
            // Monthly basics of the people raised: before and after.
            $table->unsignedBigInteger('current_minor')->default(0);
            $table->unsignedBigInteger('new_minor')->default(0);
            $table->ulid('created_by')->nullable();
            $table->ulid('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->ulid('applied_by')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('pay_increment_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('round_id');
            $table->ulid('employee_id');
            $table->ulid('unit_id');
            $table->string('employee_code', 60);
            $table->string('employee_name', 150);
            // The salary the line starts from (its structure stays), and its basic.
            $table->ulid('salary_id')->nullable();
            $table->ulid('structure_id')->nullable();
            $table->unsignedBigInteger('current_minor')->default(0);
            // From the appraisal: score per mille and band key; null without one.
            $table->unsignedSmallInteger('score')->nullable();
            $table->string('band', 30)->nullable();
            $table->unsignedInteger('rate_bp')->default(0);
            // rule (from the band), hand (set by a person), none (no band, no rate)
            $table->string('rate_source', 10);
            $table->unsignedBigInteger('new_minor')->default(0);
            $table->string('reason', 300)->nullable();
            $table->boolean('excluded')->default(false);
            // Once applied: the new salary, or why this line was held (e.g. salary_changed).
            $table->ulid('applied_salary_id')->nullable();
            $table->string('problem', 40)->nullable();
            $table->timestamps();

            $table->unique(['round_id', 'employee_id']);
            $table->index(['organization_id', 'employee_id']);
        });

        Schema::create('pay_increment_approvals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('round_id');
            $table->unsignedSmallInteger('level');
            $table->ulid('user_id');
            $table->timestamps();

            $table->unique(['round_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_increment_approvals');
        Schema::dropIfExists('pay_increment_lines');
        Schema::dropIfExists('pay_increment_rounds');
    }
};
