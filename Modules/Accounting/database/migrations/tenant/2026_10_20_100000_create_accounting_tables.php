<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACC-1: the general ledger of a company (or a personal workspace).
 *
 * Tenant tables: organization_id is always the company that keeps the books;
 * branches and departments appear only as cost centres on journal lines.
 * No foreign key to platform tables (organizations, users).
 *
 * - Amounts are integer minor units with a currency code, never decimals.
 * - Posted journals and their lines are never changed or deleted: a mistake
 *   is undone by a reversing journal.
 * - acc_balances keeps posted totals per account, period and cost centre,
 *   written in the same transaction as the posting (reports read it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('parent_id')->nullable()->index();
            $table->string('code', 20);
            $table->json('name');
            $table->string('type', 20);
            // A heading that groups other accounts; nothing is posted to it.
            $table->boolean('is_group')->default(false);
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        // A key to the same table is added once its primary key exists (PostgreSQL).
        Schema::table('acc_accounts', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('acc_accounts')->restrictOnDelete();
        });

        Schema::create('acc_fiscal_years', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('name', 20);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('open');
            $table->timestamps();

            $table->unique(['organization_id', 'starts_on']);
        });

        Schema::create('acc_periods', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('fiscal_year_id')->constrained('acc_fiscal_years')->restrictOnDelete();
            $table->unsignedTinyInteger('number');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('open');
            $table->ulid('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['fiscal_year_id', 'number']);
            $table->index(['organization_id', 'starts_on']);
        });

        Schema::create('acc_journals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // Given when the journal is posted (no gaps from drafts).
            $table->string('number', 60)->nullable();
            $table->date('entry_date');
            $table->string('narration', 500);
            $table->string('status', 20);
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('total_minor')->default(0);
            // Where it came from: a person, or another module's record.
            $table->string('source_module', 50)->nullable();
            $table->string('source_type', 50)->nullable();
            $table->string('source_id', 64)->nullable();
            // Idempotency key: the same posting sent twice is applied once.
            $table->string('op_id', 64)->nullable();
            $table->ulid('reverses_id')->nullable()->index();
            $table->ulid('reversed_by_id')->nullable();
            $table->ulid('created_by')->nullable();
            $table->ulid('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->ulid('rejected_by')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'op_id']);
            $table->index(['organization_id', 'status', 'entry_date'], 'acc_journals_status_date_index');
            $table->index(['organization_id', 'source_module', 'source_type', 'source_id'], 'acc_journals_source_index');
        });

        Schema::create('acc_journal_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('journal_id')->constrained('acc_journals')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignUlid('account_id')->constrained('acc_accounts')->restrictOnDelete();
            // The company itself, or one of its branches or departments.
            $table->ulid('cost_centre_id');
            $table->unsignedBigInteger('debit_minor')->default(0);
            $table->unsignedBigInteger('credit_minor')->default(0);
            $table->string('memo', 255)->nullable();

            $table->index(['journal_id', 'line_no']);
            $table->index(['organization_id', 'account_id']);
        });

        Schema::create('acc_balances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->foreignUlid('period_id')->constrained('acc_periods')->restrictOnDelete();
            $table->ulid('cost_centre_id');
            $table->unsignedBigInteger('debit_minor')->default(0);
            $table->unsignedBigInteger('credit_minor')->default(0);
            $table->timestamps();

            $table->unique(['account_id', 'period_id', 'cost_centre_id']);
            $table->index(['organization_id', 'period_id']);
        });

        Schema::create('acc_posting_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // "payroll.salary_expense": declared in a module manifest (ledger_accounts).
            $table->string('posting_key', 100);
            $table->foreignUlid('account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'posting_key']);
        });

        Schema::create('acc_sequences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // The company the numbers belong to; one running number per fiscal year.
            $table->ulid('organization_id');
            $table->foreignUlid('fiscal_year_id')->constrained('acc_fiscal_years')->restrictOnDelete();
            $table->unsignedInteger('last');
            $table->timestamps();

            $table->unique(['organization_id', 'fiscal_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_sequences');
        Schema::dropIfExists('acc_posting_accounts');
        Schema::dropIfExists('acc_balances');
        Schema::dropIfExists('acc_journal_lines');
        Schema::dropIfExists('acc_journals');
        Schema::dropIfExists('acc_periods');
        Schema::dropIfExists('acc_fiscal_years');
        Schema::table('acc_accounts', fn (Blueprint $table) => $table->dropForeign(['parent_id']));
        Schema::dropIfExists('acc_accounts');
    }
};
