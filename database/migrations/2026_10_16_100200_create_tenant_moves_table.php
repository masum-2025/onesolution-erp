<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10-3: every move of a client tree's business data to another
 * database, with its verification report (row counts and checksums per
 * table). Kept for good; the audit log names each move too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_moves', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('root_organization_id')->constrained('organizations');
            $table->string('from_database', 40)->nullable(); // null = the main database
            $table->string('to_database', 40)->nullable();
            $table->string('status', 20); // running | completed | failed
            $table->string('reason', 500);
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('report')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->index(['root_organization_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_moves');
    }
};
