<?php

namespace App\Platform\Billing\SelfServe;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Billing\SelfServe\Events\WorkspaceRestored;
use App\Platform\Billing\SelfServe\Events\WorkspaceRestricted;
use App\Platform\Packaging\Models\Subscription;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Whether a self-serve account is in good standing, overdue, or read-only
 * for an overdue bill. Read-only only refuses changes: nothing is deleted,
 * and paying (or moving to a free plan) restores it at once.
 */
class AccountStanding
{
    public function __construct(private SelfServeAccount $account, private AuditLogger $audit) {}

    public function restrict(Organization $root, Subscription $subscription): void
    {
        if ($subscription->restricted_at !== null) {
            return;
        }

        $subscription->forceFill(['restricted_at' => CarbonImmutable::now()])->save();

        $this->audit->record(
            action: 'billing.workspace_restricted',
            target: $root,
            new: ['reason' => 'payment_overdue'],
            organizationId: $root->getKey(),
            partnerId: $root->partner_id,
        );

        WorkspaceRestricted::dispatch($root);
    }

    /**
     * Clears the overdue state once nothing is overdue any more.
     */
    public function refresh(Organization $root, Subscription $subscription, ?User $actor = null): void
    {
        if ($subscription->past_due_since === null && $subscription->restricted_at === null) {
            return;
        }

        if ($this->account->overdueInvoice($root, CarbonImmutable::now()) !== null) {
            return;
        }

        $wasRestricted = $subscription->restricted_at !== null;
        $subscription->forceFill(['past_due_since' => null, 'restricted_at' => null, 'reminders_sent' => 0])->save();

        if ($wasRestricted) {
            $this->audit->record(
                action: 'billing.workspace_restored',
                target: $root,
                old: ['reason' => 'payment_overdue'],
                actor: $actor,
                organizationId: $root->getKey(),
                partnerId: $root->partner_id,
            );

            WorkspaceRestored::dispatch($root);
        }
    }
}
