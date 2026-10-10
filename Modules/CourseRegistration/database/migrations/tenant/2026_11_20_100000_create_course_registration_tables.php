<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDU-4a: course registration (module course_registration).
 *
 * - crs_windows: when students of a session register (opens, closes) and
 *   until when they may add and drop subjects; one per session.
 * - crs_offerings: a subject taught in a session at a campus, in a group
 *   (A, B…), with a teacher (an HRM employee), seats and credits.
 * - crs_registrations: a student's registration for a session: draft ->
 *   submitted -> approved (or returned with a note); credits kept in sync.
 * - crs_registration_items: one subject of a registration. Never deleted:
 *   registered, waitlisted, dropped (in the add/drop time) or withdrawn
 *   (after it, with a reason); the outcome (completed, failed, incomplete,
 *   withdrawn) is what prerequisites are checked against.
 *
 * Credits are hundredths (300 = 3 credits), like Education's subjects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crs_windows', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('session_id');
            $table->date('opens_on');
            $table->date('closes_on');
            $table->date('add_drop_until');
            $table->ulid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'session_id'], 'crs_windows_session_unique');
        });

        Schema::create('crs_offerings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('session_id');
            $table->ulid('level_id')->nullable();
            $table->ulid('subject_id');
            $table->string('group_name', 20);
            // compulsory | elective | optional (from the curriculum, or set by hand)
            $table->string('kind', 12)->default('elective');
            $table->ulid('teacher_id')->nullable();
            $table->unsignedSmallInteger('capacity');
            $table->unsignedSmallInteger('credits_centi')->default(0);
            // open | closed | cancelled
            $table->string('status', 10)->default('open');
            $table->string('note', 300)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'session_id', 'unit_id', 'subject_id', 'group_name'], 'crs_offerings_group_unique');
            $table->index(['organization_id', 'session_id', 'level_id'], 'crs_offerings_level_index');
            $table->index(['organization_id', 'teacher_id'], 'crs_offerings_teacher_index');
        });

        Schema::create('crs_registrations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('session_id');
            $table->ulid('student_id');
            // draft | submitted | approved | returned
            $table->string('status', 12)->default('draft');
            $table->unsignedInteger('credits_centi')->default(0);
            $table->boolean('overload')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->ulid('submitted_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'session_id', 'student_id'], 'crs_registrations_student_unique');
            $table->index(['organization_id', 'session_id', 'status'], 'crs_registrations_status_index');
        });

        Schema::create('crs_registration_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('registration_id');
            $table->ulid('student_id');
            $table->ulid('session_id');
            $table->ulid('offering_id');
            $table->ulid('subject_id');
            $table->unsignedSmallInteger('credits_centi')->default(0);
            // registered | waitlisted | dropped | withdrawn
            $table->string('status', 12);
            // student | staff | section (who put it there)
            $table->string('source', 10)->default('staff');
            $table->timestamp('waitlisted_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('reason', 300)->nullable();
            // completed | failed | incomplete | withdrawn (null until known)
            $table->string('outcome', 12)->nullable();
            $table->ulid('outcome_by')->nullable();
            $table->timestamp('outcome_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->string('op_id', 64)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'registration_id'], 'crs_items_registration_index');
            $table->index(['organization_id', 'offering_id', 'status'], 'crs_items_offering_index');
            $table->index(['organization_id', 'student_id', 'subject_id'], 'crs_items_student_subject_index');
            $table->unique(['organization_id', 'op_id'], 'crs_items_op_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crs_registration_items');
        Schema::dropIfExists('crs_registrations');
        Schema::dropIfExists('crs_offerings');
        Schema::dropIfExists('crs_windows');
    }
};
