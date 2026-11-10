<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Patient billing, in the client's database: services and prices, corporate
 * and panel clients, bills and their lines, money in and out (payments,
 * advances, refunds; append-only), discount requests (maker-checker), each
 * cashier's day, and the number counters. Money in minor units with the
 * company's currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_billing_services', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->string('code', 30);
            $table->json('name');
            $table->string('category', 30);
            $table->unsignedBigInteger('price_minor');
            $table->ulid('tax_code_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->char('currency', 3)->nullable();
            $table->ulid('created_by');
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id'], 'patient_billing_services_op_unique');
            $table->index(['organization_id', 'unit_id'], 'patient_billing_services_unit_index');
            $table->unique(['organization_id', 'code'], 'patient_billing_services_code_unique');
            $table->index(['organization_id', 'category'], 'patient_billing_services_category_index');
        });

        Schema::create('patient_billing_panels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->string('name', 150);
            $table->string('kind', 30);
            $table->unsignedBigInteger('credit_limit_minor')->nullable();
            $table->unsignedInteger('discount_percent')->nullable();
            $table->boolean('is_active')->default(true);
            $table->char('currency', 3)->nullable();
            $table->ulid('created_by');
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id'], 'patient_billing_panels_op_unique');
            $table->index(['organization_id', 'unit_id'], 'patient_billing_panels_unit_index');
            $table->index(['organization_id', 'kind'], 'patient_billing_panels_kind_index');
        });

        Schema::create('patient_billing_sequences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('kind', 20);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'kind'], 'patient_billing_sequences_kind_unique');
        });

        Schema::create('patient_billing_bills', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->string('number', 30);
            $table->ulid('patient_id');
            $table->ulid('panel_id')->nullable();
            $table->string('status', 10)->default('open');
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('panel_discount_minor')->default(0);
            $table->bigInteger('discount_minor')->default(0);
            $table->string('discount_reason', 300)->nullable();
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('paid_minor')->default(0);
            $table->bigInteger('refunded_minor')->default(0);
            $table->bigInteger('panel_due_minor')->default(0);
            $table->char('currency', 3);
            $table->boolean('prices_include_tax')->default(true);
            $table->timestamp('finalized_at')->nullable();
            $table->ulid('finalized_by')->nullable();
            $table->string('void_reason', 300)->nullable();
            $table->ulid('journal_id')->nullable();
            $table->ulid('created_by');
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'number'], 'patient_billing_bills_number_unique');
            $table->unique(['organization_id', 'op_id'], 'patient_billing_bills_op_unique');
            $table->index(['organization_id', 'patient_id', 'status'], 'patient_billing_bills_patient_index');
            $table->index(['organization_id', 'panel_id', 'status'], 'patient_billing_bills_panel_index');
            $table->index(['organization_id', 'unit_id', 'created_at'], 'patient_billing_bills_unit_index');
        });

        Schema::create('patient_billing_bill_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('bill_id');
            $table->ulid('service_id');
            $table->json('description');
            $table->string('source_module', 40)->nullable();
            $table->string('source_type', 40)->nullable();
            $table->string('source_id', 64)->nullable();
            $table->bigInteger('quantity_milli');
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('gross_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->timestamp('voided_at')->nullable();
            $table->ulid('voided_by')->nullable();
            $table->ulid('created_by');
            $table->timestamps();

            $table->index(['organization_id', 'bill_id'], 'patient_billing_lines_bill_index');
            $table->unique(['organization_id', 'source_module', 'source_type', 'source_id'], 'patient_billing_lines_source_unique');
        });

        Schema::create('patient_billing_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->string('number', 30);
            $table->ulid('bill_id')->nullable();
            $table->ulid('patient_id');
            $table->string('kind', 10);
            $table->string('method', 10);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('reference', 100)->nullable();
            $table->string('reason', 300)->nullable();
            $table->ulid('cash_day_id')->nullable();
            $table->ulid('journal_id')->nullable();
            $table->ulid('received_by');
            $table->timestamp('received_at');
            $table->string('op_id', 64);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id'], 'patient_billing_payments_op_unique');
            $table->unique(['organization_id', 'number'], 'patient_billing_payments_number_unique');
            $table->index(['organization_id', 'bill_id'], 'patient_billing_payments_bill_index');
            $table->index(['organization_id', 'patient_id'], 'patient_billing_payments_patient_index');
            $table->index(['organization_id', 'cash_day_id'], 'patient_billing_payments_day_index');
        });

        Schema::create('patient_billing_discount_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('bill_id');
            $table->bigInteger('amount_minor');
            $table->unsignedInteger('percent_bp');
            $table->string('reason', 300);
            $table->string('status', 10)->default('pending');
            $table->ulid('requested_by');
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('note', 300)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'patient_billing_discounts_status_index');
            $table->index(['organization_id', 'bill_id'], 'patient_billing_discounts_bill_index');
        });

        Schema::create('patient_billing_cash_days', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('user_id');
            $table->char('currency', 3);
            $table->bigInteger('opening_float_minor')->default(0);
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->bigInteger('expected_minor')->nullable();
            $table->bigInteger('counted_minor')->nullable();
            $table->bigInteger('variance_minor')->nullable();
            $table->string('status', 10)->default('open');
            $table->ulid('reviewed_by')->nullable();
            $table->string('review_note', 300)->nullable();
            $table->ulid('journal_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['organization_id', 'user_id', 'status'], 'patient_billing_days_user_index');
            $table->index(['organization_id', 'unit_id', 'status'], 'patient_billing_days_unit_index');
        });
    }

    public function down(): void
    {
        foreach ([
            'patient_billing_cash_days', 'patient_billing_discount_requests', 'patient_billing_payments', 'patient_billing_bill_lines',
            'patient_billing_bills', 'patient_billing_sequences', 'patient_billing_panels', 'patient_billing_services',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
