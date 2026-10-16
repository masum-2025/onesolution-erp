<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TFB-1: teacher feedback, anonymous by design.
 *
 * - tfb_rounds: a feedback round at a campus for a session (dates, status,
 *   who rates, fixed when it opens); tfb_questions: its questions.
 * - tfb_raters: only that someone rated a teacher for a subject in a round,
 *   to stop a second rating; the rater is a keyed hash, never a name or id.
 * - tfb_answers and tfb_comments: the stars and words, with no rater, no
 *   time and ids whose time part is fixed (random otherwise), so nothing ties them to a
 *   rater row, not even the order they were written in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tfb_rounds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('session_id');
            $table->string('name', 100);
            $table->date('opens_on');
            $table->date('closes_on');
            $table->string('status', 20)->default('draft');
            // students | guardians | both, from the rule when the round opens.
            $table->string('who_rates', 20)->nullable();
            $table->ulid('created_by');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['organization_id', 'unit_id', 'status'], 'tfb_rounds_unit_index');
        });

        Schema::create('tfb_questions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('round_id');
            $table->json('text');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'round_id'], 'tfb_questions_round_index');
        });

        Schema::create('tfb_raters', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('round_id');
            $table->char('rater_hash', 64);
            $table->ulid('section_id');
            $table->ulid('teacher_id');
            $table->ulid('subject_id');

            $table->unique(['organization_id', 'round_id', 'rater_hash', 'teacher_id', 'subject_id'], 'tfb_raters_once_unique');
            $table->index(['organization_id', 'round_id', 'teacher_id'], 'tfb_raters_teacher_index');
        });

        Schema::create('tfb_answers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('round_id');
            $table->ulid('section_id');
            $table->ulid('teacher_id');
            $table->ulid('subject_id');
            $table->ulid('question_id');
            $table->unsignedTinyInteger('stars');

            $table->index(['organization_id', 'round_id', 'teacher_id'], 'tfb_answers_teacher_index');
        });

        Schema::create('tfb_comments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('round_id');
            $table->ulid('section_id');
            $table->ulid('teacher_id');
            $table->ulid('subject_id');
            $table->text('text');
            // pending | approved | hidden
            $table->string('status', 20)->default('pending');
            $table->ulid('decided_by')->nullable();

            $table->index(['organization_id', 'round_id', 'status'], 'tfb_comments_round_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tfb_comments');
        Schema::dropIfExists('tfb_answers');
        Schema::dropIfExists('tfb_raters');
        Schema::dropIfExists('tfb_questions');
        Schema::dropIfExists('tfb_rounds');
    }
};
