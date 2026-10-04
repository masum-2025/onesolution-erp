<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ATT-1: attendance of a company's employees (in the client's database).
 *
 * organization_id is the company on every table (one tenant scope); unit_id
 * keeps the employee's branch or department at the time, for views and
 * permissions. Employees are HRM's: only their ids are stored here.
 *
 * - att_shifts: working hours (minutes after midnight; ending at or before
 *   the start = ends the next day) and unpaid break.
 * - att_holidays: days off for the company, or for one unit and below it.
 * - att_rosters: which shift an employee works from a day (to a day).
 * - att_punches: check-ins and outs as they happened (UTC), never changed;
 *   a mistaken one is voided with a reason. op_id makes a repeat harmless.
 * - att_days: each employee's day worked out from shift, days off and
 *   punches (recalculated when something changes).
 * - att_corrections: a forgotten or wrong punch, asked with a reason and
 *   decided by another person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('att_shifts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('code', 20);
            $table->json('name');
            $table->unsignedSmallInteger('start_minute');
            $table->unsignedSmallInteger('end_minute');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('att_holidays', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // null: the whole company; otherwise this unit and the units below it.
            $table->ulid('unit_id')->nullable();
            $table->date('on');
            $table->json('name');
            $table->timestamps();

            $table->index(['organization_id', 'on']);
        });

        Schema::create('att_rosters', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('employee_id');
            $table->foreignUlid('shift_id')->nullable()->constrained('att_shifts')->restrictOnDelete();
            $table->date('from');
            $table->date('to')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'employee_id', 'from']);
        });

        Schema::create('att_punches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('employee_id');
            $table->timestamp('punched_at');
            // self, manual, correction (device and offline come with ATT-3)
            $table->string('source', 20);
            $table->string('op_id', 64)->nullable();
            $table->string('note', 300)->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->ulid('voided_by')->nullable();
            $table->string('void_reason', 300)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'op_id']);
            $table->index(['employee_id', 'punched_at']);
        });

        Schema::create('att_days', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('employee_id');
            $table->date('work_date');
            $table->ulid('shift_id')->nullable();
            $table->string('status', 20);
            $table->timestamp('first_in_at')->nullable();
            $table->timestamp('last_out_at')->nullable();
            $table->unsignedSmallInteger('worked_minutes')->default(0);
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('overtime_minutes')->default(0);
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
            $table->index(['organization_id', 'work_date']);
            $table->index(['unit_id', 'work_date']);
        });

        Schema::create('att_corrections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('employee_id');
            $table->date('work_date');
            $table->timestamp('in_at')->nullable();
            $table->timestamp('out_at')->nullable();
            $table->string('reason', 500);
            // pending, approved, rejected
            $table->string('status', 20);
            $table->ulid('requested_by');
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['employee_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('att_corrections');
        Schema::dropIfExists('att_days');
        Schema::dropIfExists('att_punches');
        Schema::dropIfExists('att_rosters');
        Schema::dropIfExists('att_holidays');
        Schema::dropIfExists('att_shifts');
    }
};
