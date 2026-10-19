<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * LV-1: half days of leave without pay in the month (from Attendance's
 * summary, which has them from the Leave module), kept on the slip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_slips', function (Blueprint $table) {
            $table->unsignedSmallInteger('unpaid_leave_halves')->default(0)->after('early_days');
        });
    }

    public function down(): void
    {
        Schema::table('pay_slips', function (Blueprint $table) {
            $table->dropColumn('unpaid_leave_halves');
        });
    }
};
