<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * LV-1: approved leave on a day (from the Leave module through
 * Attendance's LeaveSource contract): paid, unpaid, paid_half, unpaid_half.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('att_days', function (Blueprint $table) {
            $table->string('leave', 12)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('att_days', function (Blueprint $table) {
            $table->dropColumn('leave');
        });
    }
};
