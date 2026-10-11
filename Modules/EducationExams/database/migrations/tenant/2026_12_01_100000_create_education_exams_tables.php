<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EXM-1: exams and marks (module education_exams).
 *
 * - exm_grading_schemes + exm_grading_bands: how a percentage becomes a
 *   letter and a grade point (BD school GPA 5, BD university 4, percentage,
 *   the institution's own); which bands pass.
 * - exm_exams: an exam of a session at a campus (term, final, class test,
 *   assignment): draft -> marking -> closed -> published.
 * - exm_papers + exm_paper_parts: a subject's paper for a class in an exam,
 *   with full and pass marks, its parts (creative, MCQ, practical…), and
 *   its routine (day, time, room).
 * - exm_mark_sheets: a paper for one section: who enters it (an HRM
 *   employee; else the class teacher), open -> submitted -> approved (or
 *   returned with a note).
 * - exm_marks: one student's marks in one part of a paper (or absent).
 *
 * Marks are hundredths (82.5 -> 8250); percentages basis points (80 % ->
 * 8000); grade points hundredths (5.00 -> 500). Never floats.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exm_grading_schemes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('code', 30);
            $table->json('name');
            // The highest grade point (500 for GPA 5, 400 for 4; 0 when the scheme has no points).
            $table->unsignedSmallInteger('max_point_centi')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'exm_grading_schemes_code_unique');
        });

        Schema::create('exm_grading_bands', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('scheme_id');
            // From this percentage (basis points) up to the next band.
            $table->unsignedSmallInteger('min_percent_bp');
            $table->string('letter', 10);
            $table->unsignedSmallInteger('point_centi')->default(0);
            $table->boolean('is_pass')->default(true);
            $table->timestamps();

            $table->unique(['scheme_id', 'min_percent_bp'], 'exm_grading_bands_min_unique');
            $table->index(['organization_id', 'scheme_id']);
        });

        Schema::create('exm_exams', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('session_id');
            $table->json('name');
            // term | final | test | assignment | other
            $table->string('kind', 12);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->ulid('grading_scheme_id');
            // draft | marking | closed | published
            $table->string('status', 10)->default('draft');
            $table->ulid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['organization_id', 'session_id', 'status'], 'exm_exams_session_index');
        });

        Schema::create('exm_papers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('exam_id');
            $table->ulid('level_id');
            $table->ulid('subject_id');
            // compulsory | elective | optional (the "4th subject" when optional)
            $table->string('kind', 12)->default('compulsory');
            $table->unsignedInteger('full_marks_centi');
            $table->unsignedInteger('pass_marks_centi')->default(0);
            $table->date('sits_on')->nullable();
            $table->string('starts_at', 5)->nullable();
            $table->unsignedSmallInteger('minutes')->nullable();
            $table->string('room', 40)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['exam_id', 'level_id', 'subject_id'], 'exm_papers_subject_unique');
            $table->index(['organization_id', 'exam_id']);
        });

        Schema::create('exm_paper_parts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('paper_id');
            $table->string('code', 12);
            $table->json('name');
            $table->unsignedInteger('full_marks_centi');
            // Empty: no pass mark of its own (only the paper's).
            $table->unsignedInteger('pass_marks_centi')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['paper_id', 'code'], 'exm_paper_parts_code_unique');
            $table->index(['organization_id', 'paper_id']);
        });

        Schema::create('exm_mark_sheets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('exam_id');
            $table->ulid('paper_id');
            $table->ulid('section_id');
            // An HRM employee who enters it; empty: the section's class teacher.
            $table->ulid('teacher_id')->nullable();
            // open | submitted | approved | returned
            $table->string('status', 10)->default('open');
            $table->ulid('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('note', 300)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['paper_id', 'section_id'], 'exm_mark_sheets_section_unique');
            $table->index(['organization_id', 'exam_id', 'status'], 'exm_mark_sheets_exam_index');
            $table->index(['organization_id', 'teacher_id']);
        });

        Schema::create('exm_marks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('sheet_id');
            $table->ulid('paper_id');
            $table->ulid('part_id');
            $table->ulid('student_id');
            $table->unsignedInteger('marks_centi')->nullable();
            $table->boolean('absent')->default(false);
            $table->ulid('entered_by')->nullable();
            $table->timestamps();

            $table->unique(['sheet_id', 'student_id', 'part_id'], 'exm_marks_entry_unique');
            $table->index(['organization_id', 'paper_id', 'student_id'], 'exm_marks_student_index');
        });
    }

    public function down(): void
    {
        foreach (['exm_marks', 'exm_mark_sheets', 'exm_paper_parts', 'exm_papers', 'exm_exams', 'exm_grading_bands', 'exm_grading_schemes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
