<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SC-5a: money the customer paid online through a link (the gateway
 * confirmed it) has nobody who recorded it: recorded_by may be empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_order_payments', function (Blueprint $table) {
            $table->ulid('recorded_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Payments made online would block the column going back; they keep an empty recorder.
    }
};
