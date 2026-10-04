<?php

namespace Modules\Attendance\Console;

use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Modules\Attendance\Models\Punch;
use Modules\Attendance\Services\Locations;

/**
 * Every night: where check-ins were made is forgotten once older than each
 * company's rule (attendance.location_retention_days); how far from the
 * workplace stays. Runs even where Attendance is off (the points are still
 * personal data).
 */
class ForgetLocations extends Command
{
    protected $signature = 'attendance:forget-locations';

    protected $description = 'Forget the points of old check-ins (privacy rule).';

    public function handle(TenantDatabases $databases, Locations $locations): int
    {
        $count = 0;
        foreach ([null, ...$databases->names()] as $database) {
            $databases->onConnection($databases->connectionFor($database), function () use ($locations, &$count) {
                $companies = Punch::query()->withoutGlobalScope(OrganizationScope::class)->whereNotNull('latitude_micro')->distinct()->pluck('organization_id');
                foreach (Organization::query()->whereKey($companies->all())->get() as $company) {
                    $count += $locations->forget($company);
                }
            });
        }
        $this->info("Forgot {$count} location(s).");

        return self::SUCCESS;
    }
}
