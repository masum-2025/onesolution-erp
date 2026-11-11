<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Outpatient H-3b, in the client's database: what the doctor wrote for a
 * serial (complaints, findings, diagnosis, advice, follow-up), its medicines
 * and advised tests, and prescription templates. A finished consultation is
 * never changed: an amendment is a new revision with a reason, and the one
 * it replaces stays on record. Medicines and tests name the pharmacy's and
 * the lab's records only by id or code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outpatient_consultations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('serial_id');
            $table->ulid('patient_id');
            $table->ulid('doctor_id');
            $table->unsignedSmallInteger('revision')->default(1);
            $table->string('status', 10)->default('draft'); // draft, finished
            $table->text('complaints')->nullable();
            $table->json('findings')->nullable(); // bp, pulse, temperature, weight, spo2, other
            $table->text('diagnosis')->nullable();
            $table->text('advice')->nullable();
            $table->date('follow_up_on')->nullable();
            $table->string('amend_reason', 300)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->ulid('finished_by')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->ulid('lab_order_id')->nullable();
            $table->ulid('written_by');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'serial_id', 'revision'], 'outpatient_consultations_revision_unique');
            $table->index(['organization_id', 'patient_id'], 'outpatient_consultations_patient_index');
        });

        Schema::create('outpatient_prescription_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('consultation_id');
            $table->unsignedSmallInteger('position');
            $table->ulid('medicine_id')->nullable(); // Pharmacy, when it is on
            $table->string('medicine_text', 150);
            $table->string('dose', 40)->nullable(); // "1+0+1"
            $table->string('duration', 40)->nullable(); // "7 days"
            $table->string('timing', 20)->nullable(); // before_meal, after_meal, …
            $table->string('note', 200)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'consultation_id'], 'outpatient_prescription_items_consultation_index');
        });

        Schema::create('outpatient_advised_tests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('consultation_id');
            $table->unsignedSmallInteger('position');
            $table->string('test_code', 30)->nullable(); // Diagnostics, when it is on
            $table->string('test_text', 150);
            $table->timestamps();

            $table->index(['organization_id', 'consultation_id'], 'outpatient_advised_tests_consultation_index');
        });

        Schema::create('outpatient_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('doctor_id')->nullable(); // null = for every doctor of the company
            $table->string('name', 100);
            $table->json('body'); // medicines, tests, advice
            $table->ulid('created_by');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['organization_id', 'doctor_id'], 'outpatient_templates_doctor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outpatient_templates');
        Schema::dropIfExists('outpatient_advised_tests');
        Schema::dropIfExists('outpatient_prescription_items');
        Schema::dropIfExists('outpatient_consultations');
    }
};
