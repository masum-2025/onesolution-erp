<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * POL-1: on how many days of the month someone came late or left early, kept
 * on the slip so a cut by counting days shows what it came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_slips', function (Blueprint $table) {
            $table->unsignedSmallInteger('late_days')->default(0)->after('late_minutes');
            $table->unsignedSmallInteger('early_days')->default(0)->after('late_days');
        });
    }

    public function down(): void
    {
        Schema::table('pay_slips', function (Blueprint $table) {
            $table->dropColumn(['late_days', 'early_days']);
        });
    }
};
