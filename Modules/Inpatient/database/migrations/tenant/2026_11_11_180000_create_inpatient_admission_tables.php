<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Inpatient H-4a, in the client's database: beds of a ward (with their state
 * and version, so two admissions never take one bed), admissions, bed moves
 * and the daily bed charges (both append-only; one charge a bed day). Bills
 * and patients are named only by id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inpatient_beds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id'); // the ward's unit
            $table->ulid('ward_id');
            $table->string('code', 20);
            $table->string('service_code', 30); // Patient billing service: the daily charge
            $table->string('status', 12)->default('free'); // free, occupied, cleaning, blocked
            $table->ulid('admission_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'ward_id', 'code'], 'inpatient_beds_code_unique');
            $table->index(['organization_id', 'unit_id'], 'inpatient_beds_unit_index');
        });

        Schema::create('inpatient_admissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->string('number', 20);
            $table->ulid('patient_id');
            $table->ulid('doctor_user_id'); // the attending doctor: a staff member
            $table->ulid('bed_id');
            $table->timestamp('admitted_at');
            $table->string('reason', 500)->nullable();
            $table->string('guardian_name', 100)->nullable();
            $table->string('guardian_mobile', 20)->nullable();
            $table->string('status', 12)->default('admitted'); // admitted, discharged, cancelled
            $table->timestamp('discharged_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->ulid('bill_id')->nullable();
            $table->ulid('admitted_by');
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'number'], 'inpatient_admissions_number_unique');
            $table->unique(['organization_id', 'op_id'], 'inpatient_admissions_op_unique');
            $table->index(['organization_id', 'status'], 'inpatient_admissions_status_index');
            $table->index(['organization_id', 'patient_id'], 'inpatient_admissions_patient_index');
        });

        Schema::create('inpatient_bed_moves', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('admission_id');
            $table->ulid('from_bed_id')->nullable(); // null = the admission itself
            $table->ulid('to_bed_id');
            $table->timestamp('moved_at');
            $table->string('reason', 300)->nullable();
            $table->ulid('moved_by');
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'admission_id'], 'inpatient_bed_moves_admission_index');
        });

        Schema::create('inpatient_bed_charges', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('admission_id');
            $table->unsignedSmallInteger('day_no'); // 1, 2, … from admission
            $table->date('day_on'); // the company's date the bed day starts
            $table->ulid('bed_id');
            $table->string('service_code', 30);
            $table->ulid('bill_id')->nullable();
            $table->ulid('bill_line_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['organization_id', 'admission_id', 'day_no'], 'inpatient_bed_charges_day_unique');
        });

        Schema::create('inpatient_sequences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('kind', 20);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'kind'], 'inpatient_sequences_kind_unique');
        });
    }

    public function down(): void
    {
        foreach (['inpatient_sequences', 'inpatient_bed_charges', 'inpatient_bed_moves', 'inpatient_admissions', 'inpatient_beds'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
