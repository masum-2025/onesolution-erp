<?php

namespace App\Platform\Modules\Events;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A module became enabled for this organization (and, unless children
 * override, for its descendants). Dispatched only after the change commits.
 */
class ModuleEnabled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Organization $organization,
        public string $moduleKey,
        public ?User $actor,
        public string $reason,
    ) {}
}
