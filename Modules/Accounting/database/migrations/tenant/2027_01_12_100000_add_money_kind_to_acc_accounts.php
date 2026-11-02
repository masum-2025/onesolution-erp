<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Which asset accounts hold money, and how far a bank account may go below
 * zero: money_kind cash | bank | wallet (null for every other account), and
 * overdraft_limit_minor for a bank account with an overdraft (0 = none).
 * Postings that would take a money account below its floor are warned about
 * or refused by rule (accounting.negative_cash_balance / negative_bank_balance).
 * Books set up before this get the kind from the chart templates' codes.
 */
return new class extends Migration
{
    private const KINDS = ['1110' => 'cash', '1115' => 'cash', '1120' => 'bank', '1130' => 'wallet'];

    public function up(): void
    {
        Schema::table('acc_accounts', function (Blueprint $table) {
            $table->string('money_kind', 10)->nullable()->after('type');
            $table->unsignedBigInteger('overdraft_limit_minor')->default(0)->after('money_kind');
        });

        foreach (self::KINDS as $code => $kind) {
            DB::table('acc_accounts')->where('code', $code)->where('type', 'asset')->where('is_group', false)->update(['money_kind' => $kind]);
        }
    }

    public function down(): void
    {
        Schema::table('acc_accounts', function (Blueprint $table) {
            $table->dropColumn(['money_kind', 'overdraft_limit_minor']);
        });
    }
};
