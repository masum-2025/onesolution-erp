<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDU-3a: ID cards and certificates.
 *
 * - edu_document_templates: the institution's designs (ID card, certificate,
 *   letter): page, the items on it (JSON) and what is asked when issuing,
 *   in one language. Changed in place (version counts up); documents keep
 *   the design they were issued with.
 * - edu_document_assets: images used on designs (logo, signature,
 *   background), stored privately; switched off, never deleted, so old
 *   documents still print.
 * - edu_documents: the register of issued documents, append-only: number,
 *   a random verification code (in the QR), the design and the values as
 *   they were at issue, valid until, and revoked (with a reason) instead of
 *   deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edu_document_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // A ready-made design's key (null for designs made here).
            $table->string('key', 40)->nullable();
            $table->string('kind', 20);
            $table->json('name');
            $table->string('locale', 10);
            $table->json('page');
            $table->json('layout');
            $table->json('inputs')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'kind', 'status'], 'edu_doc_templates_kind_index');
            $table->unique(['organization_id', 'key'], 'edu_doc_templates_key_unique');
        });

        Schema::create('edu_document_assets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('kind', 20);
            $table->string('name', 80);
            $table->string('path', 255);
            $table->string('mime', 60);
            $table->unsignedInteger('size_bytes');
            $table->boolean('is_active')->default(true);
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'is_active'], 'edu_doc_assets_active_index');
        });

        Schema::create('edu_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('template_id');
            $table->unsignedInteger('template_version');
            $table->string('kind', 20);
            $table->json('title');
            $table->ulid('student_id');
            $table->string('number', 40);
            $table->string('code', 24);
            $table->string('locale', 10);
            // Page, items, values and images as at issue (private details only when the design asks for them).
            $table->json('snapshot');
            $table->boolean('has_sensitive')->default(false);
            $table->string('photo_path', 255)->nullable();
            $table->date('issued_on');
            $table->date('valid_until')->nullable();
            $table->ulid('issued_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->ulid('revoked_by')->nullable();
            $table->string('revoke_reason', 300)->nullable();
            $table->string('op_id', 64)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'edu_documents_code_unique');
            $table->unique(['organization_id', 'number'], 'edu_documents_number_unique');
            $table->unique(['organization_id', 'op_id'], 'edu_documents_op_unique');
            $table->index(['organization_id', 'student_id', 'kind'], 'edu_documents_student_index');
            $table->index(['organization_id', 'issued_on'], 'edu_documents_issued_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edu_documents');
        Schema::dropIfExists('edu_document_assets');
        Schema::dropIfExists('edu_document_templates');
    }
};
