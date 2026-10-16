<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDU-5a: who teaches which subject in a section ("Class 6 A, Maths:
 * Mr Rahim"). One teacher (an HRM employee) per subject and section;
 * changed in place (version counts up), taken out when no longer taught.
 * Class lectures and teacher feedback read it through AcademicDirectory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edu_subject_teachers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('section_id');
            $table->ulid('subject_id');
            // HRM employee.
            $table->ulid('teacher_id');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['organization_id', 'section_id', 'subject_id'], 'edu_subject_teachers_unique');
            $table->index(['organization_id', 'teacher_id'], 'edu_subject_teachers_teacher_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edu_subject_teachers');
    }
};
