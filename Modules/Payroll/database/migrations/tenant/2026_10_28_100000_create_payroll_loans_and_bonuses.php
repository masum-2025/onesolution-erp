<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAY-3a: loans and advances to employees, and festival bonuses.
 *
 * - pay_loans: money lent to an employee, recovered in monthly instalments
 *   from their salary. Waiting for approval (another person), active,
 *   closed when recovered; rejected or cancelled before it is paid out.
 * - pay_loan_installments: what each month does with a loan: planned when
 *   a draft payroll is calculated (gone if it is calculated again or
 *   deleted), recovered when that payroll is approved, or skipped when
 *   someone holds the month back with a reason. Recovered rows never change.
 * - pay_bonus_runs + pay_bonus_lines: a festival bonus (Eid, Puja …) paid
 *   apart from the monthly salary: draft, waiting for approval, approved
 *   (posted to the books), paid. Each line is one employee: eligible or why
 *   not, the bonus, tax at source, net.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_loans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('employee_id');
            $table->ulid('unit_id');
            $table->string('employee_code', 60);
            $table->string('employee_name', 150);
            // loan (several instalments) or advance (usually one)
            $table->string('kind', 20);
            $table->unsignedBigInteger('principal_minor');
            $table->unsignedSmallInteger('installments');
            $table->unsignedBigInteger('installment_minor');
            // First month recovered from ("2026-11").
            $table->char('start_period', 7);
            $table->date('paid_out_on');
            $table->string('reason', 300)->nullable();
            // pending_approval, active, closed, rejected, cancelled
            $table->string('status', 20);
            $table->unsignedBigInteger('recovered_minor')->default(0);
            $table->char('currency_code', 3);
            $table->ulid('created_by')->nullable();
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->ulid('journal_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['organization_id', 'employee_id']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('pay_loan_installments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('loan_id')->constrained('pay_loans')->restrictOnDelete();
            $table->char('period', 7);
            // planned (in a draft run), recovered (run approved), skipped (held back with a reason)
            $table->string('status', 20);
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->ulid('run_id')->nullable();
            $table->string('reason', 300)->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['loan_id', 'period']);
        });

        Schema::create('pay_bonus_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->json('title');
            // The day the bonus is for: who is employed then, and their service up to it.
            $table->date('bonus_on');
            // Share of the basic paid (10000 = one month's basic).
            $table->unsignedInteger('rate_bp');
            // draft, pending_approval, approved, paid
            $table->string('status', 20);
            $table->char('currency_code', 3);
            $table->unsignedInteger('employees')->default(0);
            $table->unsignedBigInteger('gross_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('net_minor')->default(0);
            $table->timestamp('calculated_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->ulid('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->ulid('journal_id')->nullable();
            $table->date('paid_on')->nullable();
            $table->ulid('payment_journal_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['organization_id', 'bonus_on']);
        });

        Schema::create('pay_bonus_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('bonus_run_id')->constrained('pay_bonus_runs')->restrictOnDelete();
            $table->ulid('employee_id');
            $table->ulid('unit_id');
            $table->string('employee_code', 60);
            $table->string('employee_name', 150);
            $table->unsignedBigInteger('basic_minor')->default(0);
            $table->unsignedSmallInteger('service_months')->default(0);
            // Why someone gets nothing: short_service, no_salary, excluded (by hand).
            $table->string('not_paid_reason', 40)->nullable();
            // Set by hand instead of the rate (null = worked out).
            $table->unsignedBigInteger('override_minor')->nullable();
            $table->boolean('excluded')->default(false);
            $table->unsignedBigInteger('gross_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('net_minor')->default(0);
            $table->timestamps();

            $table->unique(['bonus_run_id', 'employee_id']);
            $table->index(['organization_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_bonus_lines');
        Schema::dropIfExists('pay_bonus_runs');
        Schema::dropIfExists('pay_loan_installments');
        Schema::dropIfExists('pay_loans');
    }
};
