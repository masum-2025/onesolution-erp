<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Inpatient H-4b, in the client's database: doctors' round notes, nursing
 * notes (vitals and medicines given) and test orders sent to the lab, all
 * append-only; discharge summaries with revisions (a signed one is never
 * changed, an amendment is the next revision). A bed day taken back off the
 * bill at discharge or cancelling is marked, never removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inpatient_rounds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('admission_id');
            $table->ulid('doctor_user_id');
            $table->text('note');
            $table->timestamp('noted_at');
            $table->string('op_id', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['organization_id', 'op_id'], 'inpatient_rounds_op_unique');
            $table->index(['organization_id', 'admission_id'], 'inpatient_rounds_admission_index');
        });

        Schema::create('inpatient_nursing_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('admission_id');
            $table->json('vitals')->nullable();
            $table->text('note')->nullable();
            $table->json('medicines_given')->nullable();
            $table->ulid('noted_by');
            $table->timestamp('noted_at');
            $table->string('op_id', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['organization_id', 'op_id'], 'inpatient_nursing_notes_op_unique');
            $table->index(['organization_id', 'admission_id'], 'inpatient_nursing_notes_admission_index');
        });

        Schema::create('inpatient_test_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('admission_id');
            $table->json('codes');
            $table->ulid('lab_order_id')->nullable();
            $table->string('lab_order_number', 30)->nullable();
            $table->ulid('ordered_by');
            $table->timestamp('ordered_at');
            $table->string('op_id', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['organization_id', 'op_id'], 'inpatient_test_orders_op_unique');
            $table->index(['organization_id', 'admission_id'], 'inpatient_test_orders_admission_index');
        });

        Schema::create('inpatient_discharges', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('admission_id');
            $table->unsignedSmallInteger('revision')->default(1);
            $table->text('diagnosis')->nullable();
            $table->text('treatment')->nullable();
            $table->text('advice')->nullable();
            $table->date('follow_up_on')->nullable();
            $table->string('outcome', 12)->nullable(); // cured, improved, referred, lama, death
            $table->string('status', 10)->default('draft'); // draft, signed
            $table->string('amend_reason', 300)->nullable();
            $table->ulid('written_by');
            $table->ulid('signed_by')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'admission_id', 'revision'], 'inpatient_discharges_revision_unique');
        });

        Schema::table('inpatient_bed_charges', function (Blueprint $table) {
            $table->timestamp('withdrawn_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inpatient_bed_charges', function (Blueprint $table) {
            $table->dropColumn('withdrawn_at');
        });
        foreach (['inpatient_discharges', 'inpatient_test_orders', 'inpatient_nursing_notes', 'inpatient_rounds'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
