<?php

namespace App\Platform\Modules\Events;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A module stopped being enabled for this organization. Listeners apply the
 * security side effects (revoke tokens, stop sync, ...). Data is never deleted.
 */
class ModuleDisabled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Organization $organization,
        public string $moduleKey,
        public ?User $actor,
        public string $reason,
    ) {}
}
