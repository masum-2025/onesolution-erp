<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-only tables for offline sync (Phase 7): a versioned note (changed and
 * deleted offline) and a cash receipt (money: append-only), stand-ins for
 * module records before real modules with records exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixture_sync_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->string('title');
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('fixture_cash_receipts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixture_cash_receipts');
        Schema::dropIfExists('fixture_sync_notes');
    }
};
