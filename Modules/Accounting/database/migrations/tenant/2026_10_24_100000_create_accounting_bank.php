<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACC-4c: matching a bank, wallet or cash account with its statement.
 *
 * - acc_bank_formats: which columns of a statement file hold the date,
 *   description and amount, remembered per account.
 * - acc_bank_lines: statement lines (amount signed: in > 0, out < 0). The
 *   file is never kept. A fingerprint keeps the same line from coming in
 *   twice.
 * - acc_bank_matches: which posted journal lines a statement line stands
 *   for. Journal lines themselves never change.
 * - acc_reconciliations: a statement date and balance that matched the
 *   books; its lines are locked until it is reopened (with a reason).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_bank_formats', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->json('columns');
            $table->string('date_format', 20);
            $table->timestamps();

            $table->unique(['organization_id', 'account_id']);
        });

        Schema::create('acc_reconciliations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->date('statement_date');
            // The statement balance before its first line (the previous reconciliation's, or typed the first time).
            $table->bigInteger('opening_balance_minor');
            $table->bigInteger('statement_balance_minor');
            $table->bigInteger('book_balance_minor');
            // finished, reopened
            $table->string('status', 20);
            $table->ulid('finished_by');
            $table->timestamp('finished_at');
            $table->ulid('reopened_by')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopen_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status', 'statement_date'], 'acc_reconciliations_account_index');
        });

        Schema::create('acc_bank_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->date('line_date');
            $table->string('description', 255);
            $table->string('reference', 100)->nullable();
            $table->bigInteger('amount_minor');
            $table->bigInteger('matched_minor')->default(0);
            $table->char('fingerprint', 64);
            // Lines that came in together (one file).
            $table->ulid('import_id');
            $table->ulid('reconciliation_id')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'fingerprint']);
            $table->index(['account_id', 'line_date']);
            $table->index('reconciliation_id');
        });

        Schema::create('acc_bank_matches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('bank_line_id')->constrained('acc_bank_lines')->restrictOnDelete();
            // A journal line stands for one statement line at most.
            $table->ulid('journal_line_id')->unique();
            $table->bigInteger('amount_minor');
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->index('bank_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_bank_matches');
        Schema::dropIfExists('acc_bank_lines');
        Schema::dropIfExists('acc_reconciliations');
        Schema::dropIfExists('acc_bank_formats');
    }
};
