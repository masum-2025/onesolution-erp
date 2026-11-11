<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Outpatient H-3a, in the client's database: a doctor's own sign-in, weekly
 * sessions at a chamber, and the serials given for a session's date. A serial
 * keeps its number for good (a cancelled one is not reused); its fee is a line
 * on the patient's bill (Patient billing), named here only by id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outpatient_doctors', function (Blueprint $table) {
            $table->ulid('user_id')->nullable()->after('unit_id');
            $table->index(['organization_id', 'user_id'], 'outpatient_doctors_user_index');
        });

        Schema::create('outpatient_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('doctor_id');
            $table->ulid('chamber_id');
            $table->unsignedTinyInteger('weekday'); // 0 = Sunday … 6 = Saturday
            $table->char('starts_at', 5); // "17:00", the company's time zone
            $table->char('ends_at', 5);
            $table->unsignedSmallInteger('serial_limit')->nullable(); // null = rule outpatient.daily_serial_limit
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'doctor_id'], 'outpatient_schedules_doctor_index');
            $table->index(['organization_id', 'chamber_id'], 'outpatient_schedules_chamber_index');
        });

        Schema::create('outpatient_serials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id'); // the chamber's unit
            $table->ulid('doctor_id');
            $table->ulid('chamber_id');
            $table->ulid('schedule_id');
            $table->date('date');
            $table->unsignedSmallInteger('serial_no');
            $table->ulid('patient_id');
            $table->string('kind', 12); // new, follow_up
            $table->boolean('is_free')->default(false);
            $table->string('status', 12)->default('booked');
            $table->ulid('bill_id')->nullable();
            $table->ulid('bill_line_id')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('called_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->ulid('booked_by');
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'schedule_id', 'date', 'serial_no'], 'outpatient_serials_number_unique');
            $table->unique(['organization_id', 'op_id'], 'outpatient_serials_op_unique');
            $table->index(['organization_id', 'doctor_id', 'date'], 'outpatient_serials_doctor_index');
            $table->index(['organization_id', 'chamber_id', 'date'], 'outpatient_serials_chamber_index');
            $table->index(['organization_id', 'patient_id'], 'outpatient_serials_patient_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outpatient_serials');
        Schema::dropIfExists('outpatient_schedules');
        Schema::table('outpatient_doctors', function (Blueprint $table) {
            $table->dropIndex('outpatient_doctors_user_index');
            $table->dropColumn('user_id');
        });
    }
};
