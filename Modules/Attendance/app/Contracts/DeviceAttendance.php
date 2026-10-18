<?php

namespace Modules\Attendance\Contracts;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Punches from attendance terminals for modules that talk to them (ZKTeco
 * and others): each punch counts once (the same as the same punch brought
 * in from the machine's file), only on days the person is employed, and
 * each day touched is worked out again once at the end. Attendance keeps
 * its own tables; callers never reach them.
 */
interface DeviceAttendance
{
    /**
     * @param  list<array{employee_id: string, at: CarbonImmutable}>  $punches  instants (any zone)
     * @param  User|null  $actor  null when a scheduled run brings them
     * @return list<string> Per punch, in order: added, already, not_employed or unknown (no such employee).
     */
    public function record(Organization $company, array $punches, ?User $actor): array;
}
