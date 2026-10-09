<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDU-1b: promotion lists, and applications that start without a place.
 *
 * - edu_promotion_batches: one list for a session's level (or section),
 *   draft -> (waiting for approval) -> applied -> undone. Applying makes the
 *   next session's enrollments; undoing removes exactly those again while
 *   nothing has happened to them since.
 * - edu_promotion_lines: one student's decision: promote, repeat, leave or
 *   graduate, with the section in the next session and a reason.
 * - edu_admissions: program, level and session may be empty until someone
 *   places an application that came from another module (CRM).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edu_promotion_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->string('number', 40);
            $table->ulid('from_session_id');
            $table->ulid('to_session_id');
            $table->ulid('level_id');
            $table->ulid('section_id')->nullable();
            // draft | pending_approval | applied | undone | cancelled
            $table->string('status', 20)->default('draft');
            $table->string('note', 500)->nullable();
            $table->ulid('created_by')->nullable();
            $table->ulid('submitted_by')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->date('undo_until')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->ulid('undone_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'from_session_id', 'level_id'], 'edu_promotion_batches_from_index');
        });

        Schema::create('edu_promotion_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('batch_id');
            $table->ulid('student_id');
            $table->ulid('from_enrollment_id');
            // promote | repeat | leave | graduate
            $table->string('decision', 10);
            $table->ulid('to_level_id')->nullable();
            $table->ulid('to_section_id')->nullable();
            $table->string('reason', 300)->nullable();
            // How often the student was in this level before (for the rule education.max_repeats).
            $table->unsignedSmallInteger('repeats')->default(0);
            $table->ulid('result_enrollment_id')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'batch_id', 'student_id']);
        });

        Schema::table('edu_admissions', function (Blueprint $table) {
            $table->ulid('program_id')->nullable()->change();
            $table->ulid('level_id')->nullable()->change();
            $table->ulid('session_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edu_promotion_lines');
        Schema::dropIfExists('edu_promotion_batches');
        // Back to required: place (or withdraw) applications without a place first.
        Schema::table('edu_admissions', function (Blueprint $table) {
            $table->ulid('program_id')->nullable(false)->change();
            $table->ulid('level_id')->nullable(false)->change();
            $table->ulid('session_id')->nullable(false)->change();
        });
    }
};
