<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A slip line another module adds may name where it goes in the books
 * (EarningSource "posting_key"): expenses paid back with the salary reduce
 * what is owed to employees instead of being a salary cost again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_slip_lines', function (Blueprint $table) {
            $table->string('posting_key', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pay_slip_lines', function (Blueprint $table) {
            $table->dropColumn('posting_key');
        });
    }
};
