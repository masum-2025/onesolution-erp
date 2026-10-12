<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EXM-2: results.
 *
 * - exm_combinations: a final result made of several exams of a session
 *   for a class (term 30 % + final 70 %), with each exam's weight.
 * - exm_results: one student's result of an exam or a combination: totals,
 *   percentage, GPA, letter, pass or fail, failed subjects, merit position;
 *   computed again until published, then kept (corrections recompute).
 * - exm_result_subjects: the student's result in each subject.
 * - exm_corrections: changing a mark after results are published: asked
 *   with a reason, applied when another person approves (rule), audited.
 *
 * Marks hundredths, percentages basis points, grade points hundredths.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exm_combinations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('session_id');
            $table->ulid('level_id');
            $table->json('name');
            // [{exam_id, weight_bp}]; weights add up to 10 000.
            $table->json('exams');
            $table->ulid('grading_scheme_id');
            // draft | published
            $table->string('status', 10)->default('draft');
            $table->ulid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['organization_id', 'session_id']);
        });

        Schema::create('exm_results', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // exam | combination
            $table->string('source_type', 12);
            $table->ulid('source_id');
            $table->ulid('unit_id');
            $table->ulid('session_id');
            $table->ulid('level_id');
            $table->ulid('section_id')->nullable();
            $table->ulid('student_id');
            $table->unsignedInteger('marks_centi')->default(0);
            $table->unsignedInteger('full_marks_centi')->default(0);
            $table->unsignedSmallInteger('percent_bp')->default(0);
            $table->unsignedSmallInteger('gpa_centi')->nullable();
            $table->string('letter', 10)->nullable();
            $table->boolean('passed')->default(false);
            $table->unsignedSmallInteger('failed_subjects')->default(0);
            // Weighted by credits (universities) when the subjects have them.
            $table->unsignedInteger('credits_centi')->default(0);
            $table->unsignedSmallInteger('rank')->nullable();
            $table->unsignedSmallInteger('section_rank')->nullable();
            // draft | published
            $table->string('status', 10)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'student_id'], 'exm_results_student_unique');
            $table->index(['organization_id', 'student_id', 'status'], 'exm_results_student_index');
        });

        Schema::create('exm_result_subjects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('result_id');
            $table->ulid('subject_id');
            $table->ulid('paper_id')->nullable();
            // compulsory | elective | optional
            $table->string('kind', 12);
            $table->unsignedInteger('marks_centi')->default(0);
            $table->unsignedInteger('full_marks_centi')->default(0);
            $table->unsignedSmallInteger('percent_bp')->default(0);
            $table->string('letter', 10)->nullable();
            $table->unsignedSmallInteger('point_centi')->default(0);
            $table->unsignedSmallInteger('credits_centi')->default(0);
            $table->boolean('passed')->default(false);
            $table->boolean('absent')->default(false);
            $table->timestamps();

            $table->index(['organization_id', 'result_id']);
        });

        Schema::create('exm_corrections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('exam_id');
            $table->ulid('sheet_id');
            $table->ulid('part_id');
            $table->ulid('student_id');
            $table->unsignedInteger('old_marks_centi')->nullable();
            $table->boolean('old_absent')->default(false);
            $table->unsignedInteger('new_marks_centi')->nullable();
            $table->boolean('new_absent')->default(false);
            $table->string('reason', 300);
            // pending | applied | rejected
            $table->string('status', 10);
            $table->ulid('requested_by')->nullable();
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['organization_id', 'exam_id', 'status'], 'exm_corrections_exam_index');
        });
    }

    public function down(): void
    {
        foreach (['exm_corrections', 'exm_result_subjects', 'exm_results', 'exm_combinations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
