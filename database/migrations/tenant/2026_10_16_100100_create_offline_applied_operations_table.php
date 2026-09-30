<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10: which offline changes were applied, kept in the client's own
 * database and written in the same transaction as the business records.
 * The platform's sync_operations row (main database) is written afterwards;
 * if that step is lost, a resent change finds its marker here and is never
 * applied twice. No foreign keys: devices live in the main database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_applied_operations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('device_id');
            $table->string('op_id', 64);
            $table->json('result');
            $table->timestamp('applied_at');

            $table->unique(['device_id', 'op_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_applied_operations');
    }
};
