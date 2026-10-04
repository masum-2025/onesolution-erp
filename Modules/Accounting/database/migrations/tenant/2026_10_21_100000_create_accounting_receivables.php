<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACC-3a: receivables and payables of a company.
 *
 * - acc_parties: customers and vendors (one row may be both). A link to a
 *   CRM contact is only an id (CRM's tables are never read).
 * - acc_documents: sales invoices and credit notes, purchase bills and
 *   vendor credits, with their lines. Posted documents never change; they
 *   are voided (their journal reversed).
 * - acc_settlements: money received from customers and paid to vendors;
 *   acc_allocations: which document a settlement or credit pays, and how much.
 * - acc_sequences gets a kind: one running number per fiscal year and kind.
 *
 * Amounts are integer minor units; quantities are thousandths (1.5 = 1500).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_sequences', function (Blueprint $table) {
            $table->string('kind', 20)->default('journal');
        });
        Schema::table('acc_sequences', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'fiscal_year_id']);
            $table->unique(['organization_id', 'fiscal_year_id', 'kind']);
        });

        Schema::create('acc_parties', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('name', 150);
            $table->boolean('is_customer')->default(false);
            $table->boolean('is_vendor')->default(false);
            $table->string('code', 30)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 190)->nullable();
            $table->json('address')->nullable();
            $table->string('tax_number', 50)->nullable();
            // Days to pay; null = the company's rule accounting.payment_terms_days.
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->ulid('crm_contact_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('acc_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('type', 20);
            $table->string('number', 60)->nullable();
            $table->foreignUlid('party_id')->constrained('acc_parties')->restrictOnDelete();
            $table->date('issue_date');
            $table->date('due_date');
            $table->string('reference', 100)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->string('status', 20);
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('total_minor')->default(0);
            // Paid by settlements or covered by credits (for credits: how much was used).
            $table->unsignedBigInteger('allocated_minor')->default(0);
            $table->ulid('journal_id')->nullable();
            $table->ulid('created_by')->nullable();
            $table->ulid('submitted_by')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'type', 'number']);
            $table->index(['organization_id', 'type', 'status', 'due_date'], 'acc_documents_open_index');
            $table->index(['party_id', 'issue_date']);
        });

        Schema::create('acc_document_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('document_id')->constrained('acc_documents')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('description', 255);
            $table->unsignedBigInteger('quantity_milli');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('amount_minor');
            $table->foreignUlid('account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->ulid('cost_centre_id');

            $table->index(['document_id', 'line_no']);
        });

        Schema::create('acc_settlements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // receipt (from a customer) or payment (to a vendor)
            $table->string('type', 20);
            $table->string('number', 60)->nullable();
            $table->foreignUlid('party_id')->constrained('acc_parties')->restrictOnDelete();
            $table->date('settled_on');
            // The cash, bank or wallet account the money went into or came out of.
            $table->foreignUlid('account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('allocated_minor')->default(0);
            $table->char('currency_code', 3);
            $table->string('reference', 100)->nullable();
            $table->string('memo', 500)->nullable();
            $table->string('status', 20);
            // Allocations asked for while the settlement waits for approval.
            $table->json('requested_allocations')->nullable();
            $table->ulid('journal_id')->nullable();
            $table->string('op_id', 64)->nullable();
            $table->ulid('created_by')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'type', 'number']);
            $table->unique(['organization_id', 'op_id']);
            $table->index(['party_id', 'settled_on']);
        });

        Schema::create('acc_allocations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // What pays: a settlement, or a credit note / vendor credit.
            $table->ulid('settlement_id')->nullable()->index();
            $table->ulid('credit_document_id')->nullable()->index();
            $table->foreignUlid('document_id')->constrained('acc_documents')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->date('allocated_on');
            $table->ulid('created_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamp('created_at');

            $table->index(['document_id', 'allocated_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_allocations');
        Schema::dropIfExists('acc_settlements');
        Schema::dropIfExists('acc_document_lines');
        Schema::dropIfExists('acc_documents');
        Schema::dropIfExists('acc_parties');
        Schema::table('acc_sequences', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'fiscal_year_id', 'kind']);
            $table->unique(['organization_id', 'fiscal_year_id']);
        });
        Schema::table('acc_sequences', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
