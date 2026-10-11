<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FEE-4b: fee reminders sent, so each goes once: a bill's "3 days before",
 * "1 day after"… (rule education_fees.reminders), or the office's reminder
 * to a student that day. How many messages went out, never to whom (the
 * platform's delivery log keeps masked addresses).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_reminders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('student_id');
            $table->ulid('bill_id')->nullable();
            // before_3 | after_1 | after_7 | manual | paid
            $table->string('kind', 20);
            // "bill:<id>:before_3", "student:<id>:manual:2026-10-11", "receipt:<id>:paid": once each.
            $table->string('once_key', 90);
            $table->date('sent_on');
            $table->unsignedSmallInteger('messages')->default(0);
            $table->ulid('sent_by')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'once_key'], 'fee_reminders_once_unique');
            $table->index(['organization_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_reminders');
    }
};
