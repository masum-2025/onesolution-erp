<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM-1: positions, employees, their employment history and documents.
 *
 * Tenant tables (they follow their client into a dedicated database): every
 * table has organization_id, no foreign key to platform tables (organizations,
 * users); keys between HRM tables are fine.
 *
 * - hrm_employees.organization_id is the unit (company, branch or department)
 *   the person works in; company_id is its company (codes are unique there).
 * - hrm_employment_events is append-only: hire, confirm, transfer, promote,
 *   notice, exit, rehire, each with its effective date.
 * - National and tax ids are encrypted; a keyed hash finds duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hrm_positions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('code', 30)->nullable();
            $table->json('title');
            $table->string('grade', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('hrm_employees', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('company_id')->index();
            $table->ulid('user_id')->nullable()->index();
            $table->string('employee_code', 60);
            $table->string('full_name', 150);
            $table->string('full_name_local', 150)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 190)->nullable();
            $table->json('address')->nullable();
            $table->json('emergency_contact')->nullable();
            $table->text('national_id')->nullable();
            $table->string('national_id_hash', 64)->nullable();
            $table->text('tax_id')->nullable();
            $table->string('employment_type', 30);
            $table->foreignUlid('position_id')->nullable()->constrained('hrm_positions')->restrictOnDelete();
            $table->ulid('manager_id')->nullable();
            $table->string('status', 20)->index();
            $table->date('joined_on');
            $table->date('probation_ends_on')->nullable();
            $table->date('confirmed_on')->nullable();
            $table->date('notice_given_on')->nullable();
            $table->date('exits_on')->nullable();
            $table->string('exit_reason', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'employee_code']);
            $table->index(['company_id', 'national_id_hash']);
            $table->index(['organization_id', 'status']);
        });

        // A key to the same table is added once its primary key exists (PostgreSQL).
        Schema::table('hrm_employees', function (Blueprint $table) {
            $table->foreign('manager_id')->references('id')->on('hrm_employees')->nullOnDelete();
        });

        Schema::create('hrm_employment_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('employee_id')->constrained('hrm_employees')->restrictOnDelete();
            $table->string('type', 30);
            $table->date('effective_on');
            $table->json('from')->nullable();
            $table->json('to')->nullable();
            $table->string('reason', 500)->nullable();
            $table->ulid('actor_user_id')->nullable();
            $table->timestamp('created_at');

            $table->index(['employee_id', 'effective_on']);
        });

        Schema::create('hrm_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('employee_id')->constrained('hrm_employees')->restrictOnDelete();
            $table->string('type', 30);
            $table->string('title', 150);
            $table->string('file_path', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->date('expires_on')->nullable();
            $table->ulid('uploaded_by')->nullable();
            $table->timestamps();
        });

        Schema::create('hrm_code_sequences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // The company the codes belong to.
            $table->ulid('organization_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last');
            $table->timestamps();

            $table->unique(['organization_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_code_sequences');
        Schema::dropIfExists('hrm_documents');
        Schema::dropIfExists('hrm_employment_events');
        Schema::table('hrm_employees', fn (Blueprint $table) => $table->dropForeign(['manager_id']));
        Schema::dropIfExists('hrm_employees');
        Schema::dropIfExists('hrm_positions');
    }
};
