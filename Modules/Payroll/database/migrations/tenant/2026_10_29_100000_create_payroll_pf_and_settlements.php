<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAY-3b: the provident fund ledger and final settlements.
 *
 * - pay_pf_entries: each employee's provident fund, append-only: the
 *   month's contributions (employee and company share) written when that
 *   payroll is approved; what was paid out (withdrawal) and the company's
 *   share kept back (forfeit) when a final settlement is approved. Signed
 *   amounts: contributions positive, withdrawals and forfeits negative.
 * - pay_settlements + pay_settlement_lines: what is owed to (or by) someone
 *   who left: gratuity, provident fund, loans still owed, and lines added
 *   by hand (notice pay, leave encashment …). Draft, waiting for approval,
 *   approved (posted, frozen), paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_pf_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('employee_id');
            $table->ulid('unit_id');
            // contribution, withdrawal, forfeit
            $table->string('kind', 20);
            $table->char('period', 7)->nullable();
            $table->bigInteger('employee_minor')->default(0);
            $table->bigInteger('employer_minor')->default(0);
            $table->char('currency_code', 3);
            $table->ulid('run_id')->nullable();
            $table->ulid('settlement_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'employee_id']);
            $table->unique(['run_id', 'employee_id']);
        });

        Schema::create('pay_settlements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('employee_id');
            $table->ulid('unit_id');
            $table->string('employee_code', 60);
            $table->string('employee_name', 150);
            $table->date('joined_on');
            $table->date('left_on');
            $table->unsignedSmallInteger('service_years')->default(0);
            $table->unsignedSmallInteger('service_months')->default(0);
            $table->unsignedBigInteger('basic_minor')->default(0);
            // draft, pending_approval, approved, paid
            $table->string('status', 20);
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('earnings_minor')->default(0);
            $table->unsignedBigInteger('deductions_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
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

            $table->unique(['organization_id', 'employee_id']);
        });

        Schema::create('pay_settlement_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('settlement_id')->constrained('pay_settlements')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            // earning, deduction, tax, info (shown, not paid)
            $table->string('kind', 20);
            // GRATUITY, PF_EMPLOYEE, PF_EMPLOYER, PF_FORFEIT, LOAN, MANUAL, TAX
            $table->string('code', 30);
            $table->json('name');
            $table->unsignedBigInteger('amount_minor');
            $table->boolean('taxable')->default(false);
            // How it was worked out, for the screen: {"key": "...", "params": {...}}.
            $table->json('basis')->nullable();
            // Added by hand (kept when calculated again).
            $table->boolean('manual')->default(false);
            $table->ulid('loan_id')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->index(['settlement_id', 'line_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_settlement_lines');
        Schema::dropIfExists('pay_settlements');
        Schema::dropIfExists('pay_pf_entries');
    }
};
