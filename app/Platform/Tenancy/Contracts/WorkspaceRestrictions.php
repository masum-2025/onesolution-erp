<?php

namespace App\Platform\Tenancy\Contracts;

use App\Platform\Tenancy\Models\Organization;

/**
 * Other parts of the platform may limit a whole account (e.g. read-only
 * while a self-serve bill is overdue, Phase 5C-2) without Tenancy knowing
 * their tables. Returns the reason ("payment_overdue"), or null when the
 * account works normally. Data is never touched, only changes are refused.
 */
interface WorkspaceRestrictions
{
    public function readOnlyReason(Organization $root): ?string;
}
