<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EXM-4: results told to families (rule education_exams.notify_results),
 * so each goes once: a result when it is published, or the office sending
 * it again that day. How many messages went out, never to whom (the
 * platform's delivery log keeps masked addresses).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exm_notices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('student_id');
            $table->ulid('result_id');
            // published | again
            $table->string('kind', 20);
            // "result:<id>:published", "result:<id>:again:2026-10-12": once each.
            $table->string('once_key', 90);
            $table->date('sent_on');
            $table->unsignedSmallInteger('messages')->default(0);
            $table->ulid('sent_by')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'once_key'], 'exm_notices_once_unique');
            $table->index(['organization_id', 'result_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exm_notices');
    }
};
