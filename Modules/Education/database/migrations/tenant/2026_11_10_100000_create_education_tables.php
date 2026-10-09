<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDU-1a: an education institution's structure, students, guardians,
 * admissions and enrollments (in the client's database).
 *
 * organization_id is the company (the institution) on every table; unit_id
 * is the campus (a branch) where a record lives. Names people read are
 * translatable JSON ({en, bn, …}). Credits are integer hundredths (1.5 ->
 * 150). Dates are calendar dates; times UTC. extra holds the institution's
 * own fields (edu_fields), checked on the way in.
 *
 * Structure:
 * - edu_academic_units: faculty / department / institute (any depth, own names).
 * - edu_programs: what students follow ("Secondary", "BSc CSE"), with how it
 *   moves on (year | semester | term), how many periods, and what its levels
 *   and sections are called there.
 * - edu_levels: steps of a program (Class 6 … 10, Semester 1 … 8); next_level_id
 *   is where promotion goes, none on the last one.
 * - edu_lists: the institution's own lists (shift, medium, stream, guardian
 *   relation, student category).
 * - edu_academic_years, edu_sessions: years, and the periods taught in them
 *   (a whole year, semesters or terms).
 * - edu_sections: a session's group of one level (Class 6 – A, morning, Bangla medium) at a campus.
 * - edu_batches: an intake (CSE 2026 Spring) and the curriculum it follows.
 * - edu_subjects, edu_curricula, edu_curriculum_items, edu_subject_prerequisites.
 *
 * People:
 * - edu_students (sensitive fields encrypted), edu_guardians, edu_student_guardians.
 * - edu_admissions: an application and its decision; admitted -> a student.
 * - edu_enrollments: a student in a session, level and section. A row is
 *   never rewritten for a new period: each period has its own, so the full
 *   history stays (promoted, repeated, left, graduated).
 * - edu_fields: the institution's own fields; edu_sequences: numbers per kind and year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edu_academic_units', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('parent_id')->nullable();
            // faculty | department | institute | wing | other
            $table->string('kind', 20);
            $table->json('name');
            $table->string('code', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('edu_programs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('academic_unit_id')->nullable();
            $table->json('name');
            $table->string('code', 20);
            // year | semester | term
            $table->string('progression', 10);
            // Periods (sessions) in one academic year: 1 for year, 2 or 3 for semesters, up to 4 terms.
            $table->unsignedTinyInteger('periods_per_year')->default(1);
            $table->unsignedSmallInteger('total_credits_centi')->nullable();
            // What a level and a section are called here: {"en": "Class", "bn": "শ্রেণি"}.
            $table->json('level_label')->nullable();
            $table->json('section_label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('edu_levels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('program_id');
            $table->unsignedSmallInteger('sequence');
            $table->json('name');
            $table->string('code', 20);
            $table->ulid('next_level_id')->nullable();
            $table->unsignedSmallInteger('min_age')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'program_id', 'code']);
            $table->index(['organization_id', 'program_id', 'sequence']);
        });

        Schema::create('edu_lists', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // shift | medium | stream | relation | category
            $table->string('kind', 20);
            $table->string('key', 40);
            $table->json('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'kind', 'key']);
        });

        Schema::create('edu_academic_years', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('name', 40);
            $table->date('starts_on');
            $table->date('ends_on');
            // planned | open | closed
            $table->string('status', 10)->default('planned');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('edu_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('academic_year_id');
            // year | semester | term (programs of the same progression use it)
            $table->string('kind', 10);
            $table->unsignedTinyInteger('sequence')->default(1);
            $table->json('name');
            $table->date('starts_on');
            $table->date('ends_on');
            // planned | open | closed
            $table->string('status', 10)->default('planned');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'academic_year_id', 'kind', 'sequence'], 'edu_sessions_period_unique');
        });

        Schema::create('edu_sections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('session_id');
            $table->ulid('level_id');
            $table->string('name', 40);
            $table->ulid('stream_id')->nullable();
            $table->ulid('shift_id')->nullable();
            $table->ulid('medium_id')->nullable();
            $table->unsignedSmallInteger('capacity');
            // An HRM employee, read through HRM's public directory (no key to HRM's tables).
            $table->ulid('class_teacher_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'session_id', 'level_id', 'unit_id', 'name'], 'edu_sections_name_unique');
            $table->index(['organization_id', 'class_teacher_id']);
        });

        Schema::create('edu_subjects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('code', 20);
            $table->json('name');
            $table->unsignedSmallInteger('credits_centi')->nullable();
            // theory | lab | practical | other
            $table->string('kind', 12)->default('theory');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('edu_curricula', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('program_id');
            $table->string('name', 60);
            // Batches that start from this year on follow it, unless a batch names another.
            $table->unsignedSmallInteger('effective_from_year');
            // draft | active | retired
            $table->string('status', 10)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'program_id', 'name']);
        });

        Schema::create('edu_curriculum_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('curriculum_id');
            $table->ulid('level_id');
            $table->ulid('subject_id');
            // compulsory | elective | optional (a "fourth subject")
            $table->string('kind', 12)->default('compulsory');
            $table->ulid('stream_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'curriculum_id', 'level_id', 'subject_id', 'stream_id'], 'edu_curriculum_items_unique');
        });

        Schema::create('edu_subject_prerequisites', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('subject_id');
            $table->ulid('requires_subject_id');
            $table->timestamps();

            $table->unique(['organization_id', 'subject_id', 'requires_subject_id'], 'edu_prerequisites_unique');
        });

        Schema::create('edu_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('program_id');
            $table->string('name', 60);
            $table->ulid('intake_session_id')->nullable();
            $table->ulid('curriculum_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'program_id', 'name']);
        });

        Schema::create('edu_students', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->string('code', 40);
            $table->string('admission_no', 40)->nullable();
            $table->string('name', 150);
            $table->string('name_local', 150)->nullable();
            // A key of the gender list people choose from; null = not given.
            $table->string('gender', 20)->nullable();
            // Sensitive: shown only with education.view_sensitive.
            $table->date('date_of_birth')->nullable();
            // Encrypted at rest; searched by a keyed hash of the number.
            $table->text('birth_registration_no')->nullable();
            $table->string('birth_registration_hash', 64)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('photo_path')->nullable();
            $table->ulid('program_id');
            $table->ulid('batch_id')->nullable();
            $table->ulid('category_id')->nullable();
            // active | suspended | left | graduated
            $table->string('status', 12)->default('active');
            $table->date('admitted_on');
            $table->date('left_on')->nullable();
            $table->string('left_reason', 300)->nullable();
            // A portal account of their own (university students), linked through the portal.
            $table->ulid('user_id')->nullable();
            $table->json('extra')->nullable();
            $table->ulid('created_by')->nullable();
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->unique(['organization_id', 'op_id']);
            $table->index(['organization_id', 'unit_id', 'status']);
            $table->index(['organization_id', 'name']);
            $table->index(['organization_id', 'birth_registration_hash']);
        });

        Schema::create('edu_guardians', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('name', 150);
            $table->string('phone', 20)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('occupation', 100)->nullable();
            // Encrypted at rest.
            $table->text('national_id')->nullable();
            $table->ulid('user_id')->nullable();
            $table->json('extra')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['organization_id', 'phone']);
        });

        Schema::create('edu_student_guardians', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('student_id');
            $table->ulid('guardian_id');
            // A key of the relation list (father, mother, guardian, …).
            $table->string('relation', 40);
            $table->boolean('is_primary')->default(false);
            $table->boolean('can_pick_up')->default(true);
            $table->boolean('receives_notices')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'student_id', 'guardian_id'], 'edu_student_guardians_unique');
            $table->index(['organization_id', 'guardian_id']);
        });

        Schema::create('edu_admissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->string('number', 40);
            $table->ulid('program_id');
            $table->ulid('level_id');
            $table->ulid('session_id');
            // The applicant as given: name, date of birth, guardians, own fields (checked when admitted).
            $table->json('applicant');
            // direct | crm | online
            $table->string('source', 12)->default('direct');
            $table->string('source_ref', 40)->nullable();
            // applied | test | offered | admitted | rejected | withdrawn
            $table->string('status', 12)->default('applied');
            $table->string('note', 500)->nullable();
            $table->ulid('student_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->ulid('decided_by')->nullable();
            $table->ulid('created_by')->nullable();
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'op_id']);
            $table->unique(['organization_id', 'source', 'source_ref']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('edu_enrollments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('student_id');
            $table->ulid('session_id');
            $table->ulid('level_id');
            $table->ulid('section_id')->nullable();
            $table->unsignedInteger('roll_no')->nullable();
            // active | promoted | repeated | left | graduated | transferred
            $table->string('status', 12)->default('active');
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            // The promotion that ended it or started it (EDU-1b).
            $table->ulid('promotion_line_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'student_id', 'session_id']);
            $table->index(['organization_id', 'section_id', 'status']);
            $table->index(['organization_id', 'session_id', 'level_id']);
        });

        Schema::create('edu_fields', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // student | guardian | admission
            $table->string('entity', 12);
            $table->string('key', 40);
            $table->json('label');
            // text | long_text | number | date | choice | multi_choice | yes_no
            $table->string('type', 14);
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('portal_visible')->default(false);
            $table->boolean('on_documents')->default(false);
            $table->boolean('is_sensitive')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'entity', 'key']);
        });

        Schema::create('edu_sequences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // student | admission
            $table->string('kind', 12);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'kind', 'year']);
        });
    }

    public function down(): void
    {
        foreach ([
            'edu_sequences', 'edu_fields', 'edu_enrollments', 'edu_admissions', 'edu_student_guardians', 'edu_guardians',
            'edu_students', 'edu_batches', 'edu_subject_prerequisites', 'edu_curriculum_items', 'edu_curricula', 'edu_subjects',
            'edu_sections', 'edu_sessions', 'edu_academic_years', 'edu_lists', 'edu_levels', 'edu_programs', 'edu_academic_units',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
