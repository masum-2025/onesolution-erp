<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STA-3: messages to families about a day's attendance.
 *
 * - sta_notices: one row per student, day and kind ("absent": not in
 *   school; "skipped": in the morning, missing a later period), so each
 *   goes once however often the sheet is saved; how many messages went.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sta_notices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('student_id');
            $table->date('taken_on');
            $table->string('kind', 10);
            $table->ulid('sheet_id');
            $table->unsignedSmallInteger('messages')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'student_id', 'taken_on', 'kind'], 'sta_notices_once_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sta_notices');
    }
};
