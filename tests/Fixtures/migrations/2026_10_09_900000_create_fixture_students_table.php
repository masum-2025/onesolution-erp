<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-only table for Tests\Fixtures\FixtureStudent: a stand-in for a
 * school module's students, to prove portal isolation before real modules
 * with records exist (Phase 5C-4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixture_students', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('name');
            $table->string('class_name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixture_students');
    }
};
