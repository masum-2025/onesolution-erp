<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Patients, in the client's database: one record per patient for the whole
 * company (registered at a branch), the patient-number counter, who opened a
 * record (append-only), the history other modules write, the staff who care
 * for a patient, and merge requests (maker-checker). ID number and allergies
 * are stored encrypted; the ID number also as a keyed hash for lookups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients_patients', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->string('mrn', 30);
            $table->string('name', 150);
            $table->string('name_local', 150)->nullable();
            $table->string('sex', 10);
            $table->date('date_of_birth')->nullable();
            $table->boolean('dob_estimated')->default(false);
            $table->string('mobile', 20)->nullable();
            $table->string('id_type', 20)->default('none');
            $table->text('id_number')->nullable();
            $table->string('id_number_hash', 64)->nullable();
            $table->string('guardian_name', 150)->nullable();
            $table->string('guardian_relation', 30)->nullable();
            $table->string('blood_group', 5)->nullable();
            $table->text('allergies')->nullable();
            $table->string('address', 300)->nullable();
            $table->ulid('merged_into_id')->nullable();
            $table->ulid('created_by');
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'mrn'], 'patients_patients_mrn_unique');
            $table->unique(['organization_id', 'op_id'], 'patients_patients_op_unique');
            $table->index(['organization_id', 'mobile'], 'patients_patients_mobile_index');
            $table->index(['organization_id', 'name'], 'patients_patients_name_index');
            $table->index(['organization_id', 'id_number_hash'], 'patients_patients_id_hash_index');
            $table->index(['organization_id', 'unit_id'], 'patients_patients_unit_index');
        });

        Schema::create('patients_sequences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('kind', 20);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'kind'], 'patients_sequences_kind_unique');
        });

        Schema::create('patients_views', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('patient_id');
            $table->ulid('user_id');
            $table->string('purpose', 30);
            $table->timestamp('viewed_at');

            $table->index(['organization_id', 'patient_id', 'viewed_at'], 'patients_views_patient_index');
        });

        Schema::create('patients_timeline', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('patient_id');
            $table->string('source_module', 40);
            $table->string('source_type', 40);
            $table->string('source_id', 64);
            $table->json('title');
            $table->timestamp('happened_at');
            $table->timestamps();

            $table->unique(['organization_id', 'source_module', 'source_type', 'source_id'], 'patients_timeline_source_unique');
            $table->index(['organization_id', 'patient_id', 'happened_at'], 'patients_timeline_patient_index');
        });

        Schema::create('patients_care_links', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('patient_id');
            $table->ulid('user_id');
            $table->string('source_module', 40);
            $table->timestamps();

            $table->unique(['organization_id', 'patient_id', 'user_id'], 'patients_care_links_unique');
            $table->index(['organization_id', 'user_id'], 'patients_care_links_user_index');
        });

        Schema::create('patients_merges', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('source_id');
            $table->ulid('target_id');
            $table->string('reason', 300);
            $table->string('status', 20)->default('pending');
            $table->ulid('requested_by');
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'patients_merges_status_index');
            $table->index(['organization_id', 'source_id'], 'patients_merges_source_index');
        });
    }

    public function down(): void
    {
        foreach (['patients_merges', 'patients_care_links', 'patients_timeline', 'patients_views', 'patients_sequences', 'patients_patients'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
