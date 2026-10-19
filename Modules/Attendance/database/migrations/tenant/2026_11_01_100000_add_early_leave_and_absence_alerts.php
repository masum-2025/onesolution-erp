<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * POL-1: minutes someone left before the shift's end (beyond the grace), and
 * the absence streaks HR was told about (once per streak).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('att_days', function (Blueprint $table) {
            $table->unsignedSmallInteger('early_minutes')->default(0)->after('late_minutes');
        });

        Schema::create('att_absence_alerts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('unit_id');
            $table->ulid('employee_id');
            // First absent working day of the streak: one alert per streak.
            $table->date('streak_from');
            $table->unsignedSmallInteger('days');
            $table->date('sent_on');
            $table->timestamps();

            $table->unique(['employee_id', 'streak_from']);
            $table->index(['organization_id', 'sent_on']);
            $table->index(['unit_id', 'sent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('att_absence_alerts');
        Schema::table('att_days', function (Blueprint $table) {
            $table->dropColumn('early_minutes');
        });
    }
};
