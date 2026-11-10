<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Diagnostics, in the client's database: tests (priced by a billing service
 * code), their parameters and normal ranges by sex and age, referring
 * doctors, orders and their tests, barcoded samples, results (each
 * submission kept: amendments never overwrite), verifications (append-only),
 * report deliveries, and the number counters. Lab values are decimals with
 * up to four places, never floats.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostics_tests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->string('code', 30);
            $table->json('name');
            $table->string('department', 30);
            $table->string('sample_type', 30);
            $table->string('service_code', 30);
            $table->unsignedInteger('turnaround_hours')->nullable();
            $table->boolean('is_active')->default(true);
            $table->ulid('created_by');
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id'], 'diagnostics_tests_op_unique');
            $table->index(['organization_id', 'unit_id'], 'diagnostics_tests_unit_index');
            $table->unique(['organization_id', 'code'], 'diagnostics_tests_code_unique');
            $table->index(['organization_id', 'department'], 'diagnostics_tests_department_index');
            $table->index(['organization_id', 'sample_type'], 'diagnostics_tests_sample_type_index');
        });

        Schema::create('diagnostics_parameters', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('test_id');
            $table->json('name');
            $table->string('unit', 30)->nullable();
            $table->string('result_type', 10)->default('number');
            $table->json('choices')->nullable();
            $table->unsignedTinyInteger('decimals')->default(1);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'test_id'], 'diagnostics_parameters_test_index');
        });

        Schema::create('diagnostics_ranges', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('parameter_id');
            $table->string('sex', 10)->default('any');
            $table->unsignedInteger('age_from_days')->default(0);
            $table->unsignedInteger('age_to_days')->nullable();
            $table->decimal('low', 14, 4)->nullable();
            $table->decimal('high', 14, 4)->nullable();
            $table->decimal('critical_low', 14, 4)->nullable();
            $table->decimal('critical_high', 14, 4)->nullable();
            $table->string('normal_text', 150)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'parameter_id'], 'diagnostics_ranges_parameter_index');
        });

        Schema::create('diagnostics_referrers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->string('name', 150);
            $table->string('registration_no', 30)->nullable();
            $table->string('mobile', 20)->nullable();
            $table->unsignedInteger('commission_percent')->nullable();
            $table->boolean('is_active')->default(true);
            $table->ulid('created_by');
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id'], 'diagnostics_referrers_op_unique');
            $table->index(['organization_id', 'unit_id'], 'diagnostics_referrers_unit_index');
        });

        Schema::create('diagnostics_sequences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('kind', 20);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'kind'], 'diagnostics_sequences_kind_unique');
        });

        Schema::create('diagnostics_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->string('number', 30);
            $table->ulid('patient_id');
            $table->ulid('referrer_id')->nullable();
            $table->string('source_module', 40)->nullable();
            $table->string('source_id', 64)->nullable();
            $table->ulid('bill_id')->nullable();
            $table->string('status', 12)->default('ordered');
            $table->string('notes', 300)->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->ulid('created_by');
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'number'], 'diagnostics_orders_number_unique');
            $table->unique(['organization_id', 'op_id'], 'diagnostics_orders_op_unique');
            $table->index(['organization_id', 'unit_id', 'status'], 'diagnostics_orders_status_index');
            $table->index(['organization_id', 'patient_id'], 'diagnostics_orders_patient_index');
        });

        Schema::create('diagnostics_order_tests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('order_id');
            $table->ulid('test_id');
            $table->string('status', 12)->default('ordered');
            $table->ulid('sample_id')->nullable();
            $table->unsignedInteger('amendment')->default(0);
            $table->ulid('resulted_by')->nullable();
            $table->timestamp('resulted_at')->nullable();
            $table->string('send_back_note', 300)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['organization_id', 'order_id'], 'diagnostics_order_tests_order_index');
            $table->index(['organization_id', 'sample_id'], 'diagnostics_order_tests_sample_index');
        });

        Schema::create('diagnostics_samples', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('barcode', 40);
            $table->ulid('order_id');
            $table->ulid('patient_id');
            $table->string('sample_type', 30);
            $table->ulid('collected_by');
            $table->timestamp('collected_at');
            $table->string('rejected_reason', 300)->nullable();
            $table->ulid('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'barcode'], 'diagnostics_samples_barcode_unique');
            $table->index(['organization_id', 'order_id'], 'diagnostics_samples_order_index');
        });

        Schema::create('diagnostics_results', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('order_test_id');
            $table->ulid('parameter_id');
            $table->unsignedInteger('amendment');
            $table->string('value', 200)->nullable();
            $table->string('flag', 15)->nullable();
            $table->ulid('entered_by');
            $table->timestamp('entered_at');

            $table->unique(['organization_id', 'order_test_id', 'amendment', 'parameter_id'], 'diagnostics_results_unique');
        });

        Schema::create('diagnostics_verifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('order_test_id');
            $table->unsignedInteger('amendment');
            $table->ulid('verified_by');
            $table->timestamp('verified_at');
            $table->string('note', 300)->nullable();

            $table->index(['organization_id', 'order_test_id'], 'diagnostics_verifications_test_index');
        });

        Schema::create('diagnostics_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('order_id');
            $table->string('channel', 10);
            $table->ulid('sent_by')->nullable();
            $table->timestamp('sent_at');

            $table->index(['organization_id', 'order_id'], 'diagnostics_deliveries_order_index');
        });
    }

    public function down(): void
    {
        foreach ([
            'diagnostics_deliveries', 'diagnostics_verifications', 'diagnostics_results', 'diagnostics_samples', 'diagnostics_order_tests',
            'diagnostics_orders', 'diagnostics_sequences', 'diagnostics_referrers', 'diagnostics_ranges', 'diagnostics_parameters', 'diagnostics_tests',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
