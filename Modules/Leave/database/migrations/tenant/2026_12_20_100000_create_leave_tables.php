<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Leave (LV-1). Every amount of leave is in half days (2 = one day), so no
 * fractions are ever stored.
 *
 * - lv_types: each company's kinds of leave (casual, sick, earned, ...).
 * - lv_entries: the balance ledger, append-only: what was given (accrual,
 *   carried over, adjusted) and taken (approved requests, and given back
 *   when cancelled). A balance is the sum of a leave year's entries.
 * - lv_requests + lv_approvals: asking for leave and each approval step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lv_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('code', 20);
            $table->json('name');
            $table->boolean('paid')->default(true);
            // How leave comes: yearly (all at the year's start), monthly (a twelfth a month),
            // worked_days (one day for every worked_days_per_day days at work).
            $table->string('accrual', 20);
            $table->unsignedSmallInteger('allowance_halves')->default(0);
            $table->unsignedSmallInteger('worked_days_per_day')->nullable();
            $table->unsignedSmallInteger('carry_max_halves')->default(0);
            $table->boolean('half_day')->default(true);
            // Weekends and holidays inside the leave count too (e.g. maternity).
            $table->boolean('counts_days_off')->default(false);
            $table->unsignedSmallInteger('document_after_days')->nullable();
            // Only for people HR recorded with this gender (null: everyone).
            $table->string('gender', 20)->nullable();
            $table->boolean('encashable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('lv_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('employee_id');
            $table->ulid('type_id');
            $table->unsignedSmallInteger('leave_year');
            $table->date('from_date');
            $table->date('to_date');
            // Starts in the afternoon / ends at noon (half days at the ends).
            $table->boolean('from_half')->default(false);
            $table->boolean('to_half')->default(false);
            $table->unsignedSmallInteger('halves');
            // The part beyond the balance, taken without pay (leave.beyond_balance = unpaid).
            $table->unsignedSmallInteger('unpaid_halves')->default(0);
            $table->text('reason');
            $table->string('status', 20);
            $table->unsignedTinyInteger('steps_needed')->default(1);
            $table->unsignedTinyInteger('steps_done')->default(0);
            $table->ulid('requested_by');
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'op_id']);
            $table->index(['employee_id', 'from_date']);
            $table->index(['unit_id', 'status']);
            $table->index(['organization_id', 'status', 'from_date']);
        });

        Schema::create('lv_approvals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->foreignUlid('request_id')->constrained('lv_requests')->restrictOnDelete();
            $table->unsignedTinyInteger('step');
            $table->ulid('user_id');
            $table->string('decision', 20);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['request_id', 'step']);
        });

        Schema::create('lv_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('employee_id');
            $table->ulid('type_id');
            $table->unsignedSmallInteger('leave_year');
            // accrual, carried, adjusted, taken, returned.
            $table->string('kind', 20);
            // Signed: given > 0, taken < 0.
            $table->integer('halves');
            // What made it, once: an accrual period ("2026", "2026-03", "2026-03-14"), "carry-2025", a request.
            $table->string('source', 80);
            $table->ulid('request_id')->nullable();
            $table->text('note')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['employee_id', 'type_id', 'kind', 'source']);
            $table->index(['employee_id', 'type_id', 'leave_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lv_entries');
        Schema::dropIfExists('lv_approvals');
        Schema::dropIfExists('lv_requests');
        Schema::dropIfExists('lv_types');
    }
};
