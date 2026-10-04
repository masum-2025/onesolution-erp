<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACC-4b: opening balances and closing a fiscal year.
 *
 * - acc_openings / acc_opening_lines: the balances a company brings from its
 *   old books (accounts, and what each customer owes and each vendor is
 *   owed), one set per company, posted once as one journal.
 * - acc_documents.is_opening: the customer and vendor items of the opening,
 *   kept as invoices and bills so aging, statements and receipts work.
 * - acc_periods.is_closing: a one-day period at the end of a fiscal year that
 *   holds only the closing entry (income and expenses into retained
 *   earnings), so profit and loss reports leave it out.
 * - acc_fiscal_years: who closed the year and with which entry.
 * - acc_year_reopen_requests: reopening a closed year needs a reason and a
 *   second person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_fiscal_years', function (Blueprint $table) {
            $table->ulid('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->ulid('closing_journal_id')->nullable();
            $table->unsignedInteger('version')->default(1);
        });

        Schema::table('acc_periods', function (Blueprint $table) {
            $table->boolean('is_closing')->default(false);
        });

        Schema::table('acc_documents', function (Blueprint $table) {
            $table->boolean('is_opening')->default(false);
        });

        Schema::create('acc_year_reopen_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('fiscal_year_id')->constrained('acc_fiscal_years')->restrictOnDelete();
            $table->string('reason', 500);
            // pending, approved, rejected
            $table->string('status', 20);
            $table->ulid('requested_by');
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('acc_openings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->unique();
            $table->date('opening_date');
            // draft, pending_approval, rejected, posted
            $table->string('status', 20);
            $table->ulid('journal_id')->nullable();
            $table->ulid('created_by')->nullable();
            $table->ulid('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('acc_opening_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('opening_id')->constrained('acc_openings')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            // account, customer, vendor
            $table->string('kind', 20);
            $table->ulid('account_id')->nullable();
            $table->ulid('party_id')->nullable();
            $table->ulid('cost_centre_id');
            $table->unsignedBigInteger('debit_minor')->default(0);
            $table->unsignedBigInteger('credit_minor')->default(0);
            // Customer and vendor items: the old invoice or bill.
            $table->string('reference', 100)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();

            $table->index(['opening_id', 'line_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_opening_lines');
        Schema::dropIfExists('acc_openings');
        Schema::dropIfExists('acc_year_reopen_requests');
        Schema::table('acc_documents', function (Blueprint $table) {
            $table->dropColumn('is_opening');
        });
        Schema::table('acc_periods', function (Blueprint $table) {
            $table->dropColumn('is_closing');
        });
        Schema::table('acc_fiscal_years', function (Blueprint $table) {
            $table->dropColumn(['closed_by', 'closed_at', 'closing_journal_id', 'version']);
        });
    }
};
