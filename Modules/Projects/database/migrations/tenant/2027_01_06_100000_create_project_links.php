<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * PRJ-2: a project's quotes (CRM) and invoices (Accounting). Only the link:
 * numbers, statuses and amounts are always read from the module that keeps
 * them, never copied here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_project_links', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->foreignUlid('project_id')->constrained('prj_projects')->restrictOnDelete();
            // quote (crm_quotes) | invoice (acc_documents)
            $table->string('kind', 10);
            $table->ulid('target_id');
            $table->ulid('created_by')->nullable();
            $table->timestamp('created_at');
            $table->unique(['project_id', 'kind', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prj_project_links');
    }
};
