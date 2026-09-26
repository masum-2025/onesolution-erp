<?php

namespace App\Platform\Transfers\Http\Controllers\Concerns;

use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Transfers\Exceptions\TransferException;

/**
 * The client account (its top organization) seen from an organization
 * context, and whether the person acting owns the whole account: an owner
 * at the top organization, not support staff. Only they accept legal
 * documents and move the account to another provider.
 */
trait ClientAccount
{
    use FindsVisibleOrganizations;

    protected function account(string $organization): Organization
    {
        $visible = $this->findVisible($organization);

        return $visible->isRoot() ? $visible : Organization::query()->findOrFail($visible->root_id);
    }

    protected function ownsAccount(Organization $root): bool
    {
        $context = app(CurrentContext::class);

        return $context->hasOrganization()
            && ! $context->isSupport()
            && $context->organization()->is($root)
            && $context->membership()->isOwner();
    }

    protected function requireAccountOwner(Organization $root): void
    {
        if (! $this->ownsAccount($root)) {
            throw TransferException::ownersOnly();
        }
    }
}
