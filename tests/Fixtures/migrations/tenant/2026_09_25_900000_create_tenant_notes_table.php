<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-only table for Tests\Fixtures\TenantNote, a stand-in business model
 * used to prove BelongsToOrganization isolation before real modules exist.
 * A tenant table (Phase 10): it follows its client into a dedicated
 * database, so no foreign key to the organizations table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('title');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_notes');
    }
};
