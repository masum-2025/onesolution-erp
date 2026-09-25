<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-only table for Tests\Fixtures\TenantNote, a stand-in business model
 * used to prove BelongsToOrganization isolation before real modules exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('title');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_notes');
    }
};
