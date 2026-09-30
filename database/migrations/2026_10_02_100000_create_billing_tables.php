<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 5B-3: partner plans and billing.
 *
 * - partner_plans / partner_plan_prices: a partner's own plan on top of one of
 *   our plans (own name and prices, optionally fewer modules).
 * - subscriptions: one per client (top organization): which plan, which
 *   partner plan, currency, period and how far it has been billed.
 * - wholesale_prices: what we charge a wholesale partner per client or seat;
 *   partner_id null = the default for every partner. Append-only (a new row
 *   with a later effective_from replaces the price).
 * - invoices / invoice_lines: invoices and credit notes, to a partner
 *   (wholesale) or to a client (direct, revenue share). Issued documents never
 *   change; only status and paid_at move.
 * - commissions / payouts: the partner's share of revenue-share invoices.
 * - invoice_sequences: gap-free numbering, one row per series.
 *
 * Money: integer minor units + ISO currency code everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('partner_id')->constrained()->cascadeOnDelete();
            $table->string('base_plan_key', 50);
            // Translatable: {"en": "...", "bn": "..."}
            $table->json('name');
            $table->json('description')->nullable();
            // null = every module of the base plan; otherwise a subset.
            $table->json('modules')->nullable();
            $table->string('status', 20)->default('active');
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'status']);
        });

        Schema::create('partner_plan_prices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('partner_plan_id')->constrained()->cascadeOnDelete();
            $table->char('currency_code', 3);
            $table->string('period', 10);
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();

            $table->unique(['partner_plan_id', 'currency_code', 'period']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUlid('partner_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('partner_plan_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->string('period', 10)->default('monthly');
            $table->string('status', 20)->default('active');
            $table->date('started_on');
            // Last day already invoiced; null = never billed.
            $table->date('billed_through')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'status']);
        });

        Schema::create('wholesale_prices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('partner_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('plan_key', 50);
            $table->char('currency_code', 3);
            // per_client | per_seat, per month.
            $table->string('unit', 20);
            $table->unsignedBigInteger('amount_minor');
            $table->date('effective_from');
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->index(['plan_key', 'currency_code', 'effective_from']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('number', 30)->unique();
            // invoice | credit_note
            $table->string('type', 20);
            $table->ulid('credits_invoice_id')->nullable();
            // partner (wholesale) | organization (direct, revenue share)
            $table->string('billed_to', 20);
            $table->foreignUlid('partner_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('organization_id')->nullable()->constrained()->restrictOnDelete();
            // Whose brand the document carries.
            $table->foreignUlid('brand_partner_id')->constrained('partners')->restrictOnDelete();
            $table->string('billing_mode', 20);
            // One invoice per subject and period, however often the run is repeated.
            $table->string('billing_key', 120)->nullable()->unique();
            $table->char('currency_code', 3);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->unsignedBigInteger('tax_minor');
            $table->unsignedBigInteger('total_minor');
            $table->string('status', 20);
            $table->timestamp('issued_at');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->string('reason', 500)->nullable();
            // Names and addresses as they were when issued.
            $table->json('seller');
            $table->json('buyer');
            $table->timestamps();

            $table->index(['partner_id', 'billed_to', 'issued_at']);
            $table->index(['organization_id', 'issued_at']);
        });

        // A key to the same table is added once its primary key exists (PostgreSQL).
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('credits_invoice_id')->references('id')->on('invoices')->restrictOnDelete();
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignUlid('organization_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('plan_key', 50)->nullable();
            // Frozen text in both languages: {"en": "...", "bn": "..."}
            $table->json('description');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_amount_minor');
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('partner_id')->constrained()->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->bigInteger('amount_minor');
            $table->string('reference', 100);
            $table->timestamp('paid_at');
            $table->ulid('recorded_by')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'paid_at']);
        });

        Schema::create('commissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('partner_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->unsignedInteger('rate_bp');
            $table->unsignedBigInteger('base_minor');
            // Negative for a credit note's reversal.
            $table->bigInteger('amount_minor');
            // pending (invoice unpaid) | payable | paid
            $table->string('status', 20);
            $table->foreignUlid('payout_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique('invoice_id');
            $table->index(['partner_id', 'status', 'currency_code']);
        });

        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->string('series', 30)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('commissions');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('wholesale_prices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('partner_plan_prices');
        Schema::dropIfExists('partner_plans');
    }
};
