<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SC-7: a parcel the courier could not deliver and is bringing back (in the
 * client's database).
 *
 * - courier_returning_at: when the courier said "cancelled" for a shipped
 *   order (rule online_orders.courier_cancel_means_return); null again if it
 *   later says "delivered".
 * - received_back_at: when staff took the parcel back into the warehouse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->timestamp('courier_returning_at')->nullable()->after('courier_checked_at');
            $table->timestamp('received_back_at')->nullable()->after('courier_returning_at');
            $table->index(['organization_id', 'status', 'courier_returning_at'], 'online_orders_returning_idx');
        });
    }

    public function down(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->dropIndex('online_orders_returning_idx');
            $table->dropColumn(['courier_returning_at', 'received_back_at']);
        });
    }
};
