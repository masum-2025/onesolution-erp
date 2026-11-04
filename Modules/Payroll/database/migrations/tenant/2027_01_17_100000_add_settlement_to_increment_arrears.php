<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| PAY-5b: increment arrears of someone who left are paid with their final
| settlement; the row keeps which one.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_increment_arrears', function (Blueprint $table) {
            $table->ulid('settlement_id')->nullable()->after('run_id');
        });
    }

    public function down(): void
    {
        Schema::table('pay_increment_arrears', function (Blueprint $table) {
            $table->dropColumn('settlement_id');
        });
    }
};
