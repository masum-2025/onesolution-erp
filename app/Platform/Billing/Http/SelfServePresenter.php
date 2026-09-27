<?php

namespace App\Platform\Billing\Http;

use App\Models\User;
use App\Platform\Billing\SelfServe\SelfServeAccount;
use App\Platform\Billing\SelfServe\Trials;
use App\Platform\Packaging\Models\Subscription;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Payments\GatewayRegistry;
use App\Platform\Payments\Models\Payment;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * The self-serve billing screen: where the account stands, what it can buy,
 * what it owes, and whether it can pay online here.
 */
class SelfServePresenter
{
    public function __construct(
        private SelfServeAccount $account,
        private Trials $trials,
        private GatewayRegistry $gateways,
        private PlanCatalog $plans,
        private InvoicePresenter $invoices,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function state(Organization $root, Subscription $subscription, User $user): array
    {
        $planKey = $this->account->planKey($root);
        $paid = $this->account->isPaid($root, $subscription);
        $offer = $this->trials->offer($root, $subscription, $user);
        $grace = (int) $this->account->rule('billing.overdue_grace_days', $root);

        $status = match (true) {
            $subscription->restricted_at !== null => 'read_only',
            $subscription->past_due_since !== null => 'past_due',
            $subscription->onTrial() => 'trial',
            $paid => 'active',
            default => 'free',
        };

        $pending = Payment::query()
            ->where('organization_id', $root->getKey())
            ->where('status', Payment::PENDING)
            ->where('expires_at', '>', CarbonImmutable::now())
            ->latest()
            ->first();

        return [
            'organization' => ['id' => $root->getKey(), 'name' => $root->displayName()],
            'status' => $status,
            'plan' => ['key' => $planKey, 'name' => $this->name($planKey), 'paid' => $paid],
            'free_plan_key' => $this->account->freePlan($root),
            'currency' => $subscription->currency_code,
            'period' => $subscription->period,
            'paid_through' => $paid ? $subscription->billed_through?->toDateString() : null,
            'moves_to_free_on' => $subscription->cancel_at_period_end ? $subscription->billed_through?->addDay()->toDateString() : null,
            'trial' => $subscription->onTrial() ? [
                'plan_key' => $subscription->trial_plan_key,
                'ends_at' => $subscription->trial_ends_at->toIso8601String(),
            ] : null,
            'trial_offer' => $offer === null ? null : [
                'plan_key' => $offer['plan_key'],
                'plan_name' => $this->name($offer['plan_key']),
                'days' => (int) $this->account->rule('b2c.trial_days', $root),
            ],
            'past_due_since' => $subscription->past_due_since?->toIso8601String(),
            'read_only_since' => $subscription->restricted_at?->toIso8601String(),
            'read_only_from' => $subscription->past_due_since !== null && $subscription->restricted_at === null
                ? $subscription->past_due_since->addDays($grace)->toIso8601String()
                : null,
            'open_invoices' => $this->account->openInvoices($root)->map(fn ($invoice) => $this->invoices->summary($invoice))->values(),
            'plans' => $this->account->offeredPlans($subscription),
            'can_pay_online' => ($gateway = $this->gateways->available($this->account->context($root), $subscription->currency_code)[0] ?? null) !== null,
            'test_payments' => $gateway?->isTestMode() ?? false,
            'verified' => $user->email_verified_at !== null || $user->phone_verified_at !== null,
            'can_manage' => Gate::allows('billing.manage', $root),
            'pending_payment' => $pending === null ? null : ['id' => $pending->getKey(), 'created_at' => $pending->created_at->toIso8601String()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payment(Payment $payment): array
    {
        $open = $payment->isPending() && $payment->expires_at->isFuture();

        return [
            'id' => $payment->getKey(),
            'status' => $payment->status,
            'purpose' => $payment->purpose,
            'plan' => $payment->plan_key === null ? null : ['key' => $payment->plan_key, 'name' => $this->name($payment->plan_key)],
            'period' => $payment->period,
            'currency' => $payment->currency_code,
            'amount_minor' => $payment->amount_minor,
            'method' => $payment->method,
            'invoice' => $payment->invoice_id === null ? null : ['id' => $payment->invoice_id, 'number' => $payment->invoice?->number],
            'failure_code' => $payment->failure_code,
            'refund_due' => $payment->refund_due,
            // Only while it can still be completed there.
            'checkout_url' => $open ? $payment->checkout_url : null,
            'created_at' => $payment->created_at->toIso8601String(),
            'expires_at' => $payment->expires_at->toIso8601String(),
            'completed_at' => $payment->completed_at?->toIso8601String(),
        ];
    }

    private function name(string $planKey): string
    {
        return $this->plans->has($planKey) ? $this->plans->get($planKey)->label() : $planKey;
    }
}
