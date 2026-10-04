<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAY-1: a company's payroll (in the client's database).
 *
 * organization_id is the company on every table; unit_id keeps the
 * employee's branch or department at the time. Employees are HRM's, their
 * days are Attendance's: only ids are stored here. Money is integer minor
 * units of the company's currency; rates are basis points (10% = 1000).
 *
 * - pay_components: what a salary is made of (earnings and deductions).
 * - pay_structures + pay_structure_items: a salary's make-up, each item a
 *   fixed amount or a share of the basic.
 * - pay_salaries: an employee's basic and structure from a day (history kept).
 * - pay_payment_details: where an employee is paid (account number encrypted).
 * - pay_runs: a month's payroll: draft, waiting for approval, approved
 *   (posted to the books), paid. pay_run_approvals: who approved at which level.
 * - pay_slips + pay_slip_lines: each employee's pay in a run, with the days
 *   and minutes it was worked out from; frozen once the run is approved.
 * - pay_adjustments: one-off additions or deductions in a draft run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_components', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('code', 20);
            $table->json('name');
            // earning, deduction
            $table->string('kind', 20);
            $table->boolean('taxable')->default(true);
            // Prorated by days worked (earnings usually; fixed deductions not).
            $table->boolean('prorated')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('pay_structures', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('code', 20);
            $table->json('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('pay_structure_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('structure_id')->constrained('pay_structures')->restrictOnDelete();
            $table->foreignUlid('component_id')->constrained('pay_components')->restrictOnDelete();
            // fixed (amount_minor) or percent_of_basic (rate_bp)
            $table->string('calc', 20);
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->unsignedInteger('rate_bp')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);

            $table->unique(['structure_id', 'component_id']);
        });

        Schema::create('pay_salaries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('employee_id');
            $table->foreignUlid('structure_id')->constrained('pay_structures')->restrictOnDelete();
            $table->unsignedBigInteger('basic_minor');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('reason', 300)->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'employee_id', 'effective_from']);
        });

        Schema::create('pay_payment_details', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('employee_id');
            // bank, mobile, cash
            $table->string('method', 20);
            $table->string('provider', 100)->nullable();
            $table->string('account_name', 150)->nullable();
            $table->text('account_number')->nullable();
            $table->string('branch', 150)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'employee_id']);
        });

        Schema::create('pay_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // "2026-10": the month paid.
            $table->char('period', 7);
            $table->date('period_from');
            $table->date('period_to');
            // draft, pending_approval, approved, paid
            $table->string('status', 20);
            $table->char('currency_code', 3);
            $table->unsignedInteger('employees')->default(0);
            $table->unsignedBigInteger('earnings_minor')->default(0);
            $table->unsignedBigInteger('deductions_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('net_minor')->default(0);
            $table->timestamp('calculated_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->ulid('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->ulid('journal_id')->nullable();
            $table->date('paid_on')->nullable();
            $table->ulid('payment_journal_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'period']);
        });

        Schema::create('pay_run_approvals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('run_id')->constrained('pay_runs')->restrictOnDelete();
            $table->unsignedTinyInteger('level');
            $table->ulid('user_id');
            $table->timestamps();

            $table->unique(['run_id', 'level']);
            $table->unique(['run_id', 'user_id']);
        });

        Schema::create('pay_slips', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('run_id')->constrained('pay_runs')->restrictOnDelete();
            $table->ulid('unit_id');
            $table->ulid('employee_id');
            $table->string('employee_code', 60);
            $table->string('employee_name', 150);
            $table->ulid('salary_id')->nullable();
            $table->unsignedBigInteger('basic_minor')->default(0);
            // Days of the month counted, days employed in it, absences and half days from Attendance.
            $table->unsignedSmallInteger('period_days');
            $table->unsignedSmallInteger('employed_days');
            $table->unsignedSmallInteger('absent_days')->default(0);
            $table->unsignedSmallInteger('half_days')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedBigInteger('earnings_minor')->default(0);
            $table->unsignedBigInteger('deductions_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
            // A slip that cannot be paid as is (no salary, net below zero).
            $table->string('problem', 40)->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'employee_id']);
            $table->index(['organization_id', 'employee_id']);
        });

        Schema::create('pay_slip_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('slip_id')->constrained('pay_slips')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            // earning, deduction, tax
            $table->string('kind', 20);
            $table->string('code', 30);
            $table->json('name');
            $table->unsignedBigInteger('amount_minor');
            $table->boolean('taxable')->default(false);

            $table->index(['slip_id', 'line_no']);
        });

        Schema::create('pay_adjustments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('run_id')->constrained('pay_runs')->restrictOnDelete();
            $table->ulid('employee_id');
            // earning, deduction
            $table->string('kind', 20);
            $table->string('label', 120);
            $table->unsignedBigInteger('amount_minor');
            $table->boolean('taxable')->default(true);
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_adjustments');
        Schema::dropIfExists('pay_slip_lines');
        Schema::dropIfExists('pay_slips');
        Schema::dropIfExists('pay_run_approvals');
        Schema::dropIfExists('pay_runs');
        Schema::dropIfExists('pay_payment_details');
        Schema::dropIfExists('pay_salaries');
        Schema::dropIfExists('pay_structure_items');
        Schema::dropIfExists('pay_structures');
        Schema::dropIfExists('pay_components');
    }
};
