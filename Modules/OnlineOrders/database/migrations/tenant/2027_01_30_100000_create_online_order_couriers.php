<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SC-5b: couriers a company books its parcels with (in the client's database).
 *
 * - online_order_couriers: the company's own account at a courier (Steadfast):
 *   its API keys encrypted, and the hash of the token the courier sends to
 *   our webhook (the token itself is shown once).
 * - online_orders: which account carries the order, the courier's id for the
 *   parcel, and where the courier last said it stands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_order_couriers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            // A CourierDrivers key (steadfast).
            $table->string('driver', 30);
            $table->string('name', 80);
            // Encrypted JSON of the driver's credential fields.
            $table->text('credentials');
            // SHA-256 of the webhook token.
            $table->string('webhook_token_hash', 64);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['organization_id', 'is_active']);
        });

        Schema::table('online_orders', function (Blueprint $table) {
            $table->ulid('courier_id')->nullable();
            $table->string('courier_ref', 80)->nullable();
            // CourierStatus: booked, in_transit, on_hold, delivered, partly_delivered, cancelled, unknown
            $table->string('courier_status', 30)->nullable();
            $table->timestamp('courier_checked_at')->nullable();
            $table->index(['organization_id', 'courier_id', 'courier_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('online_orders', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'courier_id', 'courier_ref']);
            $table->dropColumn(['courier_id', 'courier_ref', 'courier_status', 'courier_checked_at']);
        });
        Schema::dropIfExists('online_order_couriers');
    }
};
