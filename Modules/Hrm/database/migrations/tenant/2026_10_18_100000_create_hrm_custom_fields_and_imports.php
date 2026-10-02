<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM-3a: extra employee fields an organization defines itself, and CSV
 * imports of employees.
 *
 * - hrm_custom_fields: defined at a company, branch or department; employees
 *   of that unit and the units below fill them in. The key is unique in the
 *   company and never changes; fields are switched off, never removed.
 * - hrm_employees.custom: the values, key => value (JSON, portable).
 * - hrm_imports / hrm_import_rows: an uploaded file is checked row by row and
 *   only then imported. The file itself is never kept; each row's details are
 *   encrypted and wiped once the import ends (counts and errors stay).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hrm_custom_fields', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('company_id');
            $table->string('key', 40);
            $table->json('label');
            $table->string('type', 20);
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });

        Schema::table('hrm_employees', function (Blueprint $table) {
            $table->json('custom')->nullable()->after('emergency_contact');
        });

        Schema::create('hrm_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('company_id')->index();
            $table->ulid('created_by');
            $table->string('file_name', 190);
            $table->string('status', 20);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('hrm_import_rows', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('import_id')->constrained('hrm_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_no');
            $table->text('data')->nullable();
            $table->json('errors')->nullable();
            $table->string('status', 20);
            $table->foreignUlid('employee_id')->nullable()->constrained('hrm_employees')->nullOnDelete();
            $table->timestamps();

            $table->unique(['import_id', 'row_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_import_rows');
        Schema::dropIfExists('hrm_imports');
        Schema::table('hrm_employees', function (Blueprint $table) {
            $table->dropColumn('custom');
        });
        Schema::dropIfExists('hrm_custom_fields');
    }
};
