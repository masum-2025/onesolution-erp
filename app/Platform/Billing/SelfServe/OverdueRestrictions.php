<?php

namespace App\Platform\Billing\SelfServe;

use App\Platform\Packaging\Models\Subscription;
use App\Platform\Tenancy\Contracts\WorkspaceRestrictions;
use App\Platform\Tenancy\Models\Organization;

/**
 * Tells Tenancy that a self-serve account is read-only for an overdue bill
 * (set by Dunning, cleared by paying or moving to the free plan).
 */
class OverdueRestrictions implements WorkspaceRestrictions
{
    public function readOnlyReason(Organization $root): ?string
    {
        $restricted = Subscription::query()
            ->where('organization_id', $root->getKey())
            ->where('self_serve', true)
            ->whereNotNull('restricted_at')
            ->exists();

        return $restricted ? 'payment_overdue' : null;
    }
}
