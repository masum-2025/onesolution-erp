<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SC-6: the cash a courier collected on delivery, and the payouts it sends
 * back (in the client's database).
 *
 * - online_order_courier_payouts: one payout from one courier account: what
 *   arrived and where (bank, mobile wallet, cash), the courier's charge, and
 *   any difference with its reason. Written once, never changed.
 * - online_order_payments.courier_payout_id: the payout that settled a
 *   payment the courier collected (method "courier"); null while it owes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_order_courier_payouts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('courier_id');
            // bank, mobile, cash: where the money arrived.
            $table->string('method', 10);
            $table->char('currency_code', 3);
            // What the courier collected for the payments settled (their sum).
            $table->unsignedBigInteger('collected_minor');
            $table->unsignedBigInteger('received_minor');
            $table->unsignedBigInteger('fee_minor')->default(0);
            // collected - received - fee: above 0 short, below 0 more than owed.
            $table->bigInteger('difference_minor')->default(0);
            $table->unsignedInteger('payments_count');
            $table->date('paid_on');
            $table->string('reference', 80)->nullable();
            $table->string('reason', 300)->nullable();
            $table->ulid('journal_id')->nullable();
            $table->ulid('recorded_by');
            $table->string('op_id', 64);
            $table->timestamp('created_at')->nullable();
            $table->unique(['organization_id', 'op_id'], 'oo_courier_payouts_op_unique');
            $table->index(['organization_id', 'courier_id', 'paid_on'], 'oo_courier_payouts_courier_idx');
        });

        Schema::table('online_order_payments', function (Blueprint $table) {
            $table->ulid('courier_payout_id')->nullable()->after('method');
            $table->index(['organization_id', 'method', 'courier_payout_id'], 'online_order_payments_courier_idx');
        });
    }

    public function down(): void
    {
        Schema::table('online_order_payments', function (Blueprint $table) {
            $table->dropIndex('online_order_payments_courier_idx');
            $table->dropColumn('courier_payout_id');
        });
        Schema::dropIfExists('online_order_courier_payouts');
    }
};
