<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STA-1: students' attendance.
 *
 * - sta_periods: a campus's periods of the day (1st, 2nd…), with times;
 *   used when the rule student_attendance.by_period is on.
 * - sta_sheets: one section's attendance on one day, for the whole day
 *   (slot "day") or for one period (slot = the period's id), so a section
 *   has one sheet per day and slot. Keeps how many were present, absent,
 *   late and on leave for the lists. op_id makes a resent sheet harmless;
 *   version guards two people saving at once.
 * - sta_marks: each student's state on a sheet (present, absent, late,
 *   leave); taken_on is the sheet's day, for counting a student's days.
 * - sta_holidays: days the institution (or one campus, or some classes) is
 *   closed, beyond the weekly days off (rule student_attendance.weekend_days).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sta_periods', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->unsignedSmallInteger('number');
            $table->json('name');
            // Local times "HH:MM" (the screen picks the period running now).
            $table->char('starts_at', 5)->nullable();
            $table->char('ends_at', 5)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'unit_id', 'number'], 'sta_periods_number_unique');
        });

        Schema::create('sta_sheets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // The section's campus and session, kept for listing without Education.
            $table->ulid('unit_id');
            $table->ulid('session_id');
            $table->ulid('section_id');
            $table->date('taken_on');
            // "day", or the period's id.
            $table->string('slot', 26);
            $table->ulid('period_id')->nullable();
            $table->ulid('subject_id')->nullable();
            // HRM employee who teaches it (class teacher for a day sheet), when known.
            $table->ulid('teacher_id')->nullable();
            $table->ulid('taken_by');
            $table->unsignedSmallInteger('present_count')->default(0);
            $table->unsignedSmallInteger('absent_count')->default(0);
            $table->unsignedSmallInteger('late_count')->default(0);
            $table->unsignedSmallInteger('leave_count')->default(0);
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'section_id', 'taken_on', 'slot'], 'sta_sheets_slot_unique');
            $table->unique(['organization_id', 'op_id'], 'sta_sheets_op_unique');
            $table->index(['organization_id', 'unit_id', 'taken_on'], 'sta_sheets_unit_index');
        });

        Schema::create('sta_marks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('sheet_id');
            $table->ulid('student_id');
            $table->date('taken_on');
            $table->string('status', 10);
            $table->string('note', 150)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'sheet_id', 'student_id'], 'sta_marks_student_unique');
            $table->index(['organization_id', 'student_id', 'taken_on'], 'sta_marks_day_index');
        });

        Schema::create('sta_holidays', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // Null: the whole institution; otherwise one campus.
            $table->ulid('unit_id')->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->json('name');
            // Null: every class; otherwise only these classes (levels).
            $table->json('level_ids')->nullable();
            $table->ulid('created_by');
            $table->timestamps();

            $table->index(['organization_id', 'starts_on'], 'sta_holidays_day_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sta_holidays');
        Schema::dropIfExists('sta_marks');
        Schema::dropIfExists('sta_sheets');
        Schema::dropIfExists('sta_periods');
    }
};
