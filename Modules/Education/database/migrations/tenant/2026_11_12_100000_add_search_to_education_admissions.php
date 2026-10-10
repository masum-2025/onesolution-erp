<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Education\Models\Admission;

/**
 * EDU-2b: applications found by the applicant's name or a phone (the
 * applicant's or a guardian's). The applicant is kept as JSON, which the
 * databases search differently, so a plain lower-case text of the names and
 * phone digits is kept beside it (written with every change).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edu_admissions', function (Blueprint $table) {
            $table->string('search_text', 500)->nullable()->after('applicant');
        });

        DB::table('edu_admissions')->orderBy('id')->select(['id', 'applicant'])->chunk(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('edu_admissions')->where('id', $row->id)->update(['search_text' => Admission::searchText((array) json_decode((string) $row->applicant, true))]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('edu_admissions', function (Blueprint $table) {
            $table->dropColumn('search_text');
        });
    }
};
