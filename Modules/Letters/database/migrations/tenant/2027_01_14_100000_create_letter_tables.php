<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Letters (LET-1): HR letters from templates.
 *
 * - let_templates: a company's letter design: its kind (appointment,
 *   experience …), a short code for numbers, the page, the letterhead, what
 *   is asked when issuing, and the body per language as blocks (headings,
 *   paragraphs, lists, tables, signature …) with {placeholders}; never HTML.
 *   Draft -> active (letters can be issued) -> retired. Changed in place;
 *   letters already issued keep their own copy.
 * - let_letters: a letter issued to one employee: its number, language, the
 *   blocks with every placeholder filled (a copy that never changes), who
 *   issued it and when, whether the employee may see it, revoked or not.
 * - let_assets: letterhead, signature and stamp images, kept privately and
 *   shown through short-lived signed links; switched off, never deleted.
 * - let_sequences: the last number per code and year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('let_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // The ready-made template it started from (once per company), or null.
            $table->string('preset', 40)->nullable();
            // appointment, joining, confirmation, increment, promotion, transfer, warning, experience, relieving, salary_certificate, noc, other
            $table->string('kind', 30);
            // In the letter's number: "HR/APT/2027/0001".
            $table->string('code', 12);
            $table->json('name');
            $table->json('page');
            $table->json('letterhead');
            $table->json('inputs')->nullable();
            $table->json('bodies');
            // Uses salary fields (only people with letters.salary design or issue it).
            $table->boolean('uses_salary')->default(false);
            // draft, active, retired
            $table->string('status', 20);
            $table->ulid('created_by')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'preset']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('let_letters', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('employee_id');
            $table->string('employee_code', 60);
            $table->string('employee_name', 150);
            $table->ulid('template_id');
            $table->unsignedInteger('template_version');
            $table->string('kind', 30);
            $table->string('number', 60);
            $table->string('locale', 10);
            $table->string('title', 200);
            // The page, letterhead and blocks with everything filled in.
            $table->json('content');
            $table->boolean('uses_salary')->default(false);
            $table->boolean('shared')->default(false);
            $table->ulid('issued_by');
            $table->timestamp('issued_at');
            $table->timestamp('revoked_at')->nullable();
            $table->ulid('revoked_by')->nullable();
            $table->string('revoke_reason', 500)->nullable();
            // Issuing many at once from one request is the same request when sent again.
            $table->string('op_id', 64)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'op_id', 'employee_id']);
            $table->index(['organization_id', 'employee_id']);
        });

        Schema::create('let_assets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // letterhead, signature, stamp, logo
            $table->string('kind', 20);
            $table->string('name', 100);
            $table->string('path', 300);
            $table->string('mime', 50);
            $table->unsignedInteger('size_bytes');
            $table->boolean('is_active')->default(true);
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'kind']);
        });

        Schema::create('let_sequences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('code', 12);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last');
            $table->timestamps();

            $table->unique(['organization_id', 'code', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('let_sequences');
        Schema::dropIfExists('let_assets');
        Schema::dropIfExists('let_letters');
        Schema::dropIfExists('let_templates');
    }
};
