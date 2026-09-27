<?php

namespace App\Platform\Identity\Actions;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Billing\SelfServe\Renewals;
use App\Platform\Billing\SelfServe\SelfServeAccount;
use App\Platform\Identity\Events\WorkspaceUpgraded;
use App\Platform\Identity\Exceptions\IdentityException;
use App\Platform\Packaging\Actions\ApplySectorPackage;
use App\Platform\Packaging\Actions\ChangePlan;
use App\Platform\Packaging\Actions\PlanChoice;
use App\Platform\Packaging\Models\Subscription;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Packaging\Services\SubscriptionService;
use App\Platform\Partners\Services\PartnerClientService;
use App\Platform\Payments\Exceptions\PaymentException;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A personal workspace becomes a company (Phase 5C-3). Same organization
 * and id, so every record, setting and invoice stays where it is; only the
 * type, name and sector change, the plan becomes a business plan, and
 * members can be added.
 *
 * Billing, without proration: a paid personal period runs to its end with
 * the company's features, and the business plan is billed from the next
 * day by the usual renewal; with nothing paid running, the first business
 * invoice is issued at once, due after the payment terms. The account stays
 * self-serve (pays online).
 */
class UpgradeWorkspace
{
    public function __construct(
        private SelfServeAccount $account,
        private SubscriptionService $subscriptions,
        private PlanCatalog $plans,
        private ChangePlan $changePlan,
        private ApplySectorPackage $sectorPackage,
        private Renewals $renewals,
        private PartnerClientService $clients,
        private AuditLogger $audit,
    ) {}

    /**
     * What upgrading would mean now.
     *
     * @return array<string, mixed>
     */
    public function preview(Organization $root, User $user): array
    {
        $subscription = $this->assertUpgradable($root, $user);
        $paidUntil = $this->paidUntil($root, $subscription);

        return [
            'plans' => $this->account->plansFor(PlanCatalog::BUSINESS, $subscription),
            'suggested_plan' => (string) $this->account->rule('b2c.upgrade_plan', $root),
            'currency' => $subscription->currency_code,
            'period' => $subscription->period,
            'sector_key' => $root->sector_key,
            // When the first business invoice comes: the day after the paid period, or now.
            'billing_starts_on' => ($paidUntil?->addDay() ?? $this->account->today())->toDateString(),
            'payment_terms_days' => (int) $this->account->rule('billing.payment_terms_days', $root),
        ];
    }

    /**
     * @param  array{name: array<string, string>, sector_key: string|null, plan_key: string, period: string}  $data
     */
    public function handle(Organization $root, User $user, array $data): Organization
    {
        $subscription = $this->assertUpgradable($root, $user);

        $plan = $data['plan_key'];
        if (! $this->plans->has($plan) || $this->plans->get($plan)->audience !== PlanCatalog::BUSINESS || ! $this->plans->get($plan)->public) {
            throw PaymentException::planNotOffered();
        }
        if (($this->account->price($plan, $subscription, $data['period']) ?? 0) < 1) {
            throw PaymentException::noPrice();
        }

        // A company is a business client: it takes one of the partner's client places.
        $this->clients->assertClientSlot($root->partner);

        return DB::transaction(function () use ($root, $user, $data, $plan, $subscription) {
            $root = Organization::query()->whereKey($root->getKey())->lockForUpdate()->firstOrFail();
            $before = ['type' => $root->type->value, 'name' => $root->texts('name'), 'plan' => $this->account->planKey($root)];
            $paidUntil = $this->paidUntil($root, $subscription);

            // The type is a tree column: only a move or this upgrade may change it.
            Organization::allowingTreeWrites(fn () => $root->forceFill([
                'type' => OrganizationType::Company,
                'name' => $data['name'],
                'sector_key' => $data['sector_key'] ?? $root->sector_key,
            ])->save());

            $this->changePlan->handle($root, new PlanChoice($plan, period: $data['period']), 'Personal workspace upgraded to a company.', $user);

            // Nothing personal carries over: no trial, no pending move to a free plan.
            Subscription::query()->whereKey($subscription->getKey())->update([
                'trial_ends_at' => null,
                'trial_plan_key' => null,
                'trial_reminded' => false,
                'cancel_at_period_end' => false,
            ]);

            $firstInvoice = $paidUntil === null ? $this->renewals->startNow($root->refresh()) : null;

            if ($root->sector_key !== null) {
                $this->sectorPackage->handle($root->refresh(), $user);
            }

            $this->audit->record(
                action: 'organization.upgraded',
                target: $root,
                old: $before,
                new: [
                    'type' => OrganizationType::Company->value,
                    'name' => $data['name'],
                    'plan' => $plan,
                    'period' => $data['period'],
                    'billing_starts_on' => ($paidUntil?->addDay() ?? $this->account->today())->toDateString(),
                    'first_invoice' => $firstInvoice,
                ],
                actor: $user,
                organizationId: $root->getKey(),
                partnerId: $root->partner_id,
            );

            WorkspaceUpgraded::dispatch($root->refresh(), $user);

            return $root;
        });
    }

    private function assertUpgradable(Organization $root, User $user): Subscription
    {
        if (! $root->isRoot() || $root->type !== OrganizationType::Personal) {
            throw IdentityException::notPersonal();
        }

        $owner = OrganizationMembership::query()
            ->where('organization_id', $root->getKey())
            ->where('user_id', $user->getKey())
            ->where('membership_type', MembershipType::Owner)
            ->exists();
        if (! $owner) {
            throw IdentityException::ownerOnly();
        }

        if (! (bool) $this->account->rule('b2c.upgrade_allowed', $root)) {
            throw IdentityException::upgradeClosed();
        }

        $subscription = $this->account->subscription($root);

        // An unpaid bill is settled first (pay it, or move to the free plan).
        $open = $this->account->openInvoices($root)->first();
        if ($open !== null) {
            throw PaymentException::payOpenInvoice($open->number);
        }

        return $subscription;
    }

    /** The last paid day of a running paid personal period; null when none runs. */
    private function paidUntil(Organization $root, Subscription $subscription): ?CarbonImmutable
    {
        $paidUntil = $subscription->billed_through;

        return $paidUntil !== null && ! $paidUntil->lessThan($this->account->today()) && $this->account->isPaid($root, $subscription)
            ? $paidUntil
            : null;
    }
}
