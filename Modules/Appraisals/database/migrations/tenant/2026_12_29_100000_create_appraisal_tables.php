<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Performance appraisals (APR-1). Scores are whole numbers per mille
 * (0-1000), never floats; comments are encrypted.
 *
 * - apr_competencies: the company's competencies (translatable name).
 * - apr_cycles: a review period at a unit (and below), the last day of
 *   each step; a draft until opened (then each employee gets a review).
 * - apr_reviews: one employee in a cycle: its step, the manager who
 *   reviews them (kept as at opening), the result.
 * - apr_goals: the employee's goals with weights (percent).
 * - apr_ratings: a goal's or a competency's rating by the employee and by
 *   the manager, with comments.
 * - apr_steps: every step of a review (append-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apr_competencies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('code', 20);
            $table->json('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedInteger('version');
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('apr_cycles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->json('name');
            $table->date('period_from');
            $table->date('period_to');
            $table->date('goals_due');
            $table->date('self_due')->nullable();
            $table->date('manager_due');
            $table->string('status', 20);
            $table->unsignedSmallInteger('goal_share_percent');
            $table->boolean('self_review');
            $table->json('scale');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->ulid('created_by');
            $table->unsignedInteger('version');
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('apr_reviews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('cycle_id')->index();
            $table->ulid('unit_id');
            $table->ulid('employee_id');
            $table->ulid('manager_employee_id')->nullable();
            $table->ulid('manager_user_id')->nullable()->index();
            $table->string('stage', 20);
            $table->timestamp('goals_submitted_at')->nullable();
            $table->text('self_summary')->nullable();
            $table->text('manager_summary')->nullable();
            $table->text('employee_comment')->nullable();
            $table->unsignedSmallInteger('goals_score')->nullable();
            $table->unsignedSmallInteger('competencies_score')->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->string('band', 30)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('version');
            $table->timestamps();
            $table->unique(['cycle_id', 'employee_id']);
            $table->index(['organization_id', 'stage']);
        });

        Schema::create('apr_goals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('review_id')->index();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('weight');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('apr_ratings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('review_id')->index();
            $table->ulid('goal_id')->nullable()->unique();
            $table->ulid('competency_id')->nullable();
            $table->unsignedTinyInteger('self_rating')->nullable();
            $table->text('self_comment')->nullable();
            $table->unsignedTinyInteger('manager_rating')->nullable();
            $table->text('manager_comment')->nullable();
            $table->timestamps();
            $table->unique(['review_id', 'competency_id']);
        });

        Schema::create('apr_steps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('review_id')->index();
            $table->string('from_stage', 20)->nullable();
            $table->string('to_stage', 20);
            $table->string('note', 500)->nullable();
            $table->ulid('user_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apr_steps');
        Schema::dropIfExists('apr_ratings');
        Schema::dropIfExists('apr_goals');
        Schema::dropIfExists('apr_reviews');
        Schema::dropIfExists('apr_cycles');
        Schema::dropIfExists('apr_competencies');
    }
};
