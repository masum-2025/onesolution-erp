<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FEE-4b: messages to people without a login (a guardian's phone or email
 * kept on a student's record). Such a delivery has no user; its address is
 * stored encrypted until the message is sent, then removed like the
 * placeholder values. The masked recipient stays for the log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->text('address')->nullable()->after('recipient');
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropColumn('address');
        });
    }
};
