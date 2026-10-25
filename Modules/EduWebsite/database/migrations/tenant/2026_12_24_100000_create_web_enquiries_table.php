<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Admission enquiries sent from a school's public website (WEB-3b). Names,
 * the mobile number, the message and the staff note are encrypted; a keyed
 * hash of the number only counts how many came from it. Removed after the
 * rule edu_website.enquiry_retention_days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_enquiries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->index();
            $table->ulid('site_id');
            $table->text('student_name');
            $table->text('guardian_name');
            $table->text('phone');
            $table->string('phone_hash', 64);
            // The class asked for (Education's level) and its name as it was then.
            $table->ulid('level_id')->nullable();
            $table->json('level_name')->nullable();
            $table->text('message')->nullable();
            $table->string('locale', 10);
            $table->string('status', 20);
            $table->text('note')->nullable();
            $table->unsignedInteger('version');
            $table->timestamps();
            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(['organization_id', 'phone_hash', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_enquiries');
    }
};
