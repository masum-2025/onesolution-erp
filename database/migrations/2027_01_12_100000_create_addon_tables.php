<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The add-on store: modules an account buys on top of its plan.
 *
 * addon_prices: monthly prices, never edited, only replaced by a newer row.
 *   kind list: what a client pays (partner_id null = the platform's price,
 *   else that partner's own price for its clients); kind wholesale: what a
 *   wholesale partner pays us per client (partner_id null = every partner).
 *
 * organization_addons: one row per account (top organization) and module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addon_prices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('partner_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('module_key', 50);
            // list | wholesale
            $table->string('kind', 20);
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->date('effective_from');
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->index(['module_key', 'kind', 'currency_code', 'effective_from']);
        });

        Schema::create('organization_addons', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('partner_id')->constrained()->cascadeOnDelete();
            $table->string('module_key', 50);
            // trial | active | ended
            $table->string('status', 20);
            $table->char('currency_code', 3)->nullable();
            // The monthly price the client pays (null: billed by its wholesale partner).
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('trial_used')->default(false);
            $table->date('period_start')->nullable();
            // Paid (or, for a wholesale partner's client, running) through this day.
            $table->date('period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('ended_at')->nullable();
            $table->ulid('bought_by')->nullable();
            $table->string('op_id', 64)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'module_key']);
            $table->unique(['organization_id', 'op_id']);
            $table->index(['status', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_addons');
        Schema::dropIfExists('addon_prices');
    }
};
