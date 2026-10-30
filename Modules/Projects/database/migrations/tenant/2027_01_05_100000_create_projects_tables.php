<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * PRJ-1: projects for clients (in the client's own database).
 *
 * - prj_templates + prj_template_tasks: the usual steps of a kind of work
 *   (a group or a company keeps them), each step some days after the start.
 * - prj_projects: a project run by a company or branch, for a client (a CRM
 *   contact, or a name typed in), with its manager, days and status.
 * - prj_tasks: the project's tasks; several people per task
 *   (prj_task_assignees); notes on a task are kept (prj_task_notes).
 * - prj_sequences: project numbers per company and year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // A group or a company: used there and below.
            $table->ulid('organization_id')->index();
            $table->json('name');
            $table->string('service', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('prj_template_tasks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('template_id')->constrained('prj_templates')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order');
            $table->json('title');
            // Due this many days after the project starts.
            $table->unsignedSmallInteger('due_after_days')->default(0);
            $table->timestamps();
            $table->index(['template_id', 'sort_order']);
        });

        Schema::create('prj_sequences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'year']);
        });

        Schema::create('prj_projects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // The company or branch that runs it.
            $table->ulid('organization_id')->index();
            $table->ulid('company_id')->index();
            $table->string('code', 40);
            $table->string('name', 200);
            // A CRM contact (organization tagged "client"), or only a name when CRM is not used.
            $table->ulid('client_contact_id')->nullable()->index();
            $table->string('client_name', 190)->nullable();
            $table->ulid('template_id')->nullable();
            $table->string('service', 120)->nullable();
            $table->ulid('manager_id')->nullable()->index();
            $table->string('status', 12);
            $table->string('status_reason', 500)->nullable();
            $table->date('starts_on');
            $table->date('due_on')->nullable();
            $table->date('finished_on')->nullable();
            $table->text('description')->nullable();
            $table->ulid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('prj_tasks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('project_id')->constrained('prj_projects')->restrictOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('status', 10);
            $table->string('priority', 10)->default('normal');
            $table->date('due_on')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('done_at')->nullable();
            $table->ulid('done_by')->nullable();
            // Overdue people were told for this due day (once per due day).
            $table->date('overdue_told_for')->nullable();
            // Made offline: the same op_id again is the same task.
            $table->string('op_id', 64)->nullable();
            $table->ulid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['project_id', 'status']);
            $table->index(['organization_id', 'due_on']);
            $table->unique(['project_id', 'op_id']);
        });

        Schema::create('prj_task_assignees', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('task_id')->constrained('prj_tasks')->cascadeOnDelete();
            $table->ulid('user_id')->index();
            // The one who leads it (optional): the others help.
            $table->boolean('is_lead')->default(false);
            $table->ulid('assigned_by')->nullable();
            $table->timestamp('created_at');
            $table->unique(['task_id', 'user_id']);
        });

        Schema::create('prj_task_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('task_id')->constrained('prj_tasks')->cascadeOnDelete();
            $table->ulid('user_id')->nullable();
            $table->text('body');
            $table->timestamp('created_at');
            $table->index(['task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_task_notes');
        Schema::dropIfExists('prj_task_assignees');
        Schema::dropIfExists('prj_tasks');
        Schema::dropIfExists('prj_projects');
        Schema::dropIfExists('prj_sequences');
        Schema::dropIfExists('prj_template_tasks');
        Schema::dropIfExists('prj_templates');
    }
};
