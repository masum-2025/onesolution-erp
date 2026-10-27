<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Recruitment (REC-1). People's details are encrypted; an email is found
 * again by its keyed hash. Salaries are minor units with the currency.
 *
 * - rec_openings: a job at a unit (an HRM position optional), open to the
 *   public careers page or not, how many people are needed.
 * - rec_candidates: a person who applied (or HR added), once per email.
 * - rec_applications: a candidate for an opening, at a stage of the
 *   company's rule; unconfirmed until the email is confirmed.
 * - rec_stage_moves: every move and decision (append-only).
 * - rec_files: CVs and papers on the private disk (signed links only).
 * - rec_interviews, rec_feedback: when, how and who; each interviewer's
 *   score and recommendation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_openings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('position_id')->nullable();
            $table->json('title');
            $table->json('description')->nullable();
            $table->string('employment_type', 30)->nullable();
            $table->unsignedSmallInteger('vacancies')->default(1);
            $table->unsignedSmallInteger('hired_count')->default(0);
            $table->char('currency', 3);
            $table->unsignedBigInteger('salary_min_minor')->nullable();
            $table->unsignedBigInteger('salary_max_minor')->nullable();
            $table->boolean('show_salary')->default(false);
            $table->boolean('is_public')->default(true);
            $table->date('closes_on')->nullable();
            $table->string('status', 20);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->ulid('created_by')->nullable();
            $table->unsignedInteger('version');
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('rec_candidates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->text('name');
            $table->text('email');
            $table->char('email_hash', 64);
            $table->text('phone')->nullable();
            $table->string('locale', 10)->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'email_hash']);
        });

        Schema::create('rec_applications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('opening_id');
            $table->ulid('unit_id');
            $table->ulid('candidate_id');
            $table->string('stage', 30);
            $table->string('status', 20);
            $table->string('source', 20);
            $table->text('cover_note')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->ulid('employee_id')->nullable();
            $table->char('confirm_token_hash', 64)->nullable();
            $table->timestamp('confirm_expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('op_id', 64)->nullable();
            $table->unsignedInteger('version');
            $table->timestamps();
            $table->unique(['opening_id', 'candidate_id']);
            $table->unique(['organization_id', 'op_id']);
            $table->index(['organization_id', 'status', 'stage']);
            $table->index('confirm_token_hash');
        });

        Schema::create('rec_stage_moves', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('application_id')->index();
            $table->string('from_stage', 30)->nullable();
            $table->string('to_stage', 30)->nullable();
            $table->string('status', 20);
            $table->string('note', 500)->nullable();
            $table->ulid('user_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('rec_files', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('application_id')->index();
            $table->string('name', 150);
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->string('path', 255);
            $table->ulid('uploaded_by')->nullable();
            $table->timestamps();
        });

        Schema::create('rec_interviews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('application_id')->index();
            $table->timestamp('starts_at');
            $table->unsignedSmallInteger('minutes');
            $table->string('mode', 20);
            $table->string('place', 300)->nullable();
            $table->json('interviewer_ids');
            $table->string('status', 20);
            $table->ulid('created_by')->nullable();
            $table->unsignedInteger('version');
            $table->timestamps();
            $table->index(['organization_id', 'status', 'starts_at']);
        });

        Schema::create('rec_feedback', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('interview_id');
            $table->ulid('application_id')->index();
            $table->ulid('user_id');
            $table->unsignedTinyInteger('score');
            $table->string('recommendation', 10);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['interview_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_feedback');
        Schema::dropIfExists('rec_interviews');
        Schema::dropIfExists('rec_files');
        Schema::dropIfExists('rec_stage_moves');
        Schema::dropIfExists('rec_applications');
        Schema::dropIfExists('rec_candidates');
        Schema::dropIfExists('rec_openings');
    }
};
