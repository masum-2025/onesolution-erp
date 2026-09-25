<?php

namespace Tests\Fixtures;

use App\Platform\Modules\Contracts\PurgesModuleData;
use App\Platform\Tenancy\Models\Organization;

/**
 * Stand-in for a module's data purger; records calls instead of deleting.
 */
class FakeHrmPurger implements PurgesModuleData
{
    /** @var list<string> */
    public static array $purged = [];

    public function purge(Organization $organization): int
    {
        self::$purged[] = $organization->getKey();

        return 3;
    }
}
