<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SC-4: how much AI a company used, per module and day (counts only, never
 * the text read). The monthly limit (rule ai_assistant.monthly_calls) is
 * checked against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->string('module', 40);
            $table->date('day');
            $table->unsignedInteger('calls')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'module', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
