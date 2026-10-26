<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Expense claims (EXP-1). Money is in minor units with the currency.
 *
 * - exp_categories: the company's kinds of expense, each posted to one of
 *   the module's expense posting keys (Accounting maps the keys to accounts).
 * - exp_claims: an employee's claim; a draft until submitted, then approved
 *   step by step (exp_approvals), then paid (cash, bank, or with a month's
 *   salary). Approved and paid claims are never changed.
 * - exp_lines: what was spent, by date and category (or kilometres driven).
 * - exp_receipts: pictures and PDFs on the private disk, shown through
 *   short signed links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exp_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('code', 20);
            $table->json('name');
            $table->string('posting_key', 60);
            $table->boolean('mileage')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedInteger('version');
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('exp_claims', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('employee_id');
            $table->string('number', 30)->nullable();
            $table->string('title', 150);
            $table->char('currency', 3);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->string('status', 20);
            $table->unsignedTinyInteger('steps_needed')->default(1);
            $table->unsignedTinyInteger('steps_done')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->ulid('decided_by')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->ulid('journal_id')->nullable();
            // How it is paid back: cash or bank now, or with a month's salary (YYYY-MM).
            $table->string('pay_method', 10)->nullable();
            $table->char('pay_period', 7)->nullable();
            $table->ulid('pay_run_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->ulid('paid_by')->nullable();
            $table->ulid('payment_journal_id')->nullable();
            $table->ulid('requested_by');
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version');
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'employee_id']);
            $table->index(['organization_id', 'pay_period', 'status']);
            $table->unique(['organization_id', 'op_id']);
            $table->unique(['organization_id', 'number']);
        });

        Schema::create('exp_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('claim_id')->index();
            $table->unsignedSmallInteger('line_no');
            $table->date('spent_on');
            $table->ulid('category_id');
            $table->string('description', 300);
            $table->unsignedBigInteger('amount_minor');
            // Kilometres driven, in tenths (12.5 km = 125), for a mileage category.
            $table->unsignedInteger('distance_tenths')->nullable();
            $table->timestamps();
        });

        Schema::create('exp_receipts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('claim_id')->index();
            $table->ulid('line_id')->nullable()->index();
            $table->string('name', 150);
            $table->string('mime', 60);
            $table->unsignedInteger('size_bytes');
            $table->string('path', 300);
            $table->ulid('uploaded_by');
            $table->timestamps();
        });

        Schema::create('exp_approvals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('claim_id')->index();
            $table->unsignedTinyInteger('step');
            $table->ulid('user_id');
            $table->string('decision', 20);
            $table->string('note', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['exp_approvals', 'exp_receipts', 'exp_lines', 'exp_claims', 'exp_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
