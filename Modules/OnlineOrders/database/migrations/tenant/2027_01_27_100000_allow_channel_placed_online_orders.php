<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SC-3: an order a channel placed by itself (a customer said yes in
 * Messenger) has nobody who typed it: created_by may be empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->ulid('created_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Orders placed by a channel would block the column going back; they keep an empty creator.
    }
};
