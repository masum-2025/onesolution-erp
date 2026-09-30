<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10-3: how far each analytics dataset has been sent to the analytics
 * store, so no row is skipped or sent twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_cursors', function (Blueprint $table) {
            $table->string('dataset', 50);
            $table->string('driver', 30);
            $table->timestamp('last_created_at')->nullable();
            $table->ulid('last_id')->nullable();
            $table->unsignedBigInteger('exported')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->primary(['dataset', 'driver']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_cursors');
    }
};
