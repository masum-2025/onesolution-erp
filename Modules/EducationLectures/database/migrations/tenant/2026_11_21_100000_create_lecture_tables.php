<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEC-1: class lectures.
 *
 * - lec_lectures: what a teacher posts for a subject of a section on a day:
 *   a title, plain text, a video link. Draft, waiting (when the institution
 *   asks for approval), published, or withdrawn with a reason (never
 *   deleted once published). op_id makes a resent post harmless.
 * - lec_files: files of a lecture, kept on the private disk under the
 *   institution; opened through a short-lived signed link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lec_lectures', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // The section's campus, session and level, kept for listing without Education.
            $table->ulid('unit_id');
            $table->ulid('session_id');
            $table->ulid('section_id');
            $table->ulid('subject_id');
            // HRM employee who teaches the subject there (null when nobody does yet).
            $table->ulid('teacher_id')->nullable();
            $table->ulid('posted_by');
            $table->date('lesson_on');
            $table->string('title', 150);
            $table->text('body')->nullable();
            $table->string('video_url', 500)->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->ulid('withdrawn_by')->nullable();
            $table->string('withdrawn_reason', 300)->nullable();
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['organization_id', 'section_id', 'status', 'lesson_on'], 'lec_lectures_section_index');
            $table->index(['organization_id', 'unit_id', 'lesson_on'], 'lec_lectures_unit_index');
            $table->index(['organization_id', 'teacher_id'], 'lec_lectures_teacher_index');
            $table->unique(['organization_id', 'op_id'], 'lec_lectures_op_unique');
        });

        Schema::create('lec_files', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('lecture_id');
            $table->string('name', 150);
            $table->string('mime', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('path', 300);
            $table->char('checksum', 64);
            $table->ulid('created_by');
            $table->timestamps();

            $table->index(['organization_id', 'lecture_id'], 'lec_files_lecture_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lec_files');
        Schema::dropIfExists('lec_lectures');
    }
};
