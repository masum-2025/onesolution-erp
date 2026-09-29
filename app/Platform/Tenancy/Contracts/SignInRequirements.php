<?php

namespace App\Platform\Tenancy\Contracts;

use App\Models\User;
use App\Platform\Tenancy\Context\CurrentContext;
use Carbon\CarbonInterface;

/**
 * What a person must have set up to work in the context just entered, e.g.
 * two-step sign-in (Phase 8-1, Identity binds it). Throws a TenancyException
 * when the context may not be used; returns quietly otherwise (the person
 * may still be inside a grace period).
 */
interface SignInRequirements
{
    public function check(User $user, CurrentContext $context): void;

    /** After check(): until when the person may still work here without what is required; null when nothing is missing. */
    public function setupDueAt(): ?CarbonInterface;
}
