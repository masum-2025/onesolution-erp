<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Self-serve billing (Phase 5C-2).
 *
 * - payments: one attempt to pay through a gateway, for a plan at checkout
 *   or for an open invoice. The amount is fixed by the server when the
 *   attempt starts; the status only moves forward. Card data never exists
 *   here: the gateway keeps it.
 * - gateway_events: every message a gateway sent us (notification or the
 *   browser coming back), kept once per gateway reference so a repeated
 *   message is applied only once.
 * - trial_grants: who already had a free trial (the person, and each
 *   verified email or phone as a hash), so a trial is given once per person.
 * - subscriptions: self-serve lifecycle (trial, cancel at period end,
 *   overdue since, read-only since, reminders sent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->foreignUlid('partner_id')->constrained('partners');
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            // checkout (a plan for a new period) | invoice (an open invoice)
            $table->string('purpose', 20);
            // The invoice paid: the open one, or the one issued when a checkout succeeds.
            $table->foreignUlid('invoice_id')->nullable()->constrained('invoices');
            $table->string('gateway', 30);
            // Idempotency key from the browser: the same click never pays twice.
            $table->string('op_id', 64);
            // Gateway session, and the gateway's own id of the completed transaction.
            $table->string('gateway_ref', 100)->nullable();
            $table->string('gateway_txn', 100)->nullable();
            // The hosted page, so a repeated click opens the same one.
            $table->string('checkout_url', 500)->nullable();
            // What was bought at checkout (null when paying an invoice).
            $table->string('plan_key', 50)->nullable();
            $table->string('period', 10)->nullable();
            $table->char('currency_code', 3);
            $table->bigInteger('subtotal_minor');
            $table->integer('tax_rate_bp');
            $table->bigInteger('tax_minor');
            $table->bigInteger('amount_minor');
            // pending → succeeded | failed | cancelled | expired | review
            $table->string('status', 20);
            $table->string('failure_code', 40)->nullable();
            // As the gateway names it (e.g. "BKASH-BKash"); never a card number.
            $table->string('method', 60)->nullable();
            // Paid, but the invoice was already paid another way: refund outside the system.
            $table->boolean('refund_due')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'op_id']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('gateway_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('gateway', 30);
            // notification | return | lookup
            $table->string('kind', 20);
            $table->string('event_ref', 150);
            $table->foreignUlid('payment_id')->nullable()->constrained('payments');
            $table->string('gateway_status', 30)->nullable();
            // What the gateway sent, without secrets or card details.
            $table->json('payload');
            $table->string('result', 30)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'event_ref']);
        });

        Schema::create('trial_grants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('partner_id')->constrained('partners');
            // "user:{id}", or sha256 of a verified email or phone.
            $table->string('subject', 80);
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('organization_id')->constrained('organizations');
            $table->string('plan_key', 50);
            $table->timestamp('created_at');

            $table->unique(['partner_id', 'subject']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            // Bought and renewed by the client itself, on its own dates (not the monthly run).
            $table->boolean('self_serve')->default(false)->after('status');
            $table->timestamp('trial_ends_at')->nullable()->after('self_serve');
            $table->string('trial_plan_key', 50)->nullable()->after('trial_ends_at');
            $table->boolean('trial_reminded')->default(false)->after('trial_plan_key');
            $table->boolean('cancel_at_period_end')->default(false)->after('trial_reminded');
            $table->timestamp('past_due_since')->nullable()->after('cancel_at_period_end');
            $table->timestamp('restricted_at')->nullable()->after('past_due_since');
            $table->unsignedTinyInteger('reminders_sent')->default(0)->after('restricted_at');

            $table->index(['self_serve', 'trial_ends_at']);
        });

        // Personal workspaces made before this phase are self-serve too.
        DB::table('subscriptions')
            ->whereIn('organization_id', DB::table('organizations')->where('type', 'personal')->select('id'))
            ->update(['self_serve' => true]);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['self_serve', 'trial_ends_at']);
            $table->dropColumn([
                'self_serve', 'trial_ends_at', 'trial_plan_key', 'trial_reminded',
                'cancel_at_period_end', 'past_due_since', 'restricted_at', 'reminders_sent',
            ]);
        });

        Schema::dropIfExists('trial_grants');
        Schema::dropIfExists('gateway_events');
        Schema::dropIfExists('payments');
    }
};
