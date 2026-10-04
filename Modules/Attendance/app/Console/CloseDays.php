<?php

namespace Modules\Attendance\Console;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Modules\Attendance\Models\Roster;
use Modules\Attendance\Services\Days;
use Modules\Attendance\Services\Workplace;
use Modules\Hrm\Directory\EmployeeDirectory;

/**
 * Every morning: yesterday (in each company's own timezone) is worked out
 * for everyone on a roster, so people who never checked in show as absent
 * without anyone opening a screen. Companies with Attendance off are
 * skipped (their data stays).
 */
class CloseDays extends Command
{
    protected $signature = 'attendance:close-days';

    protected $description = 'Work out yesterday for every rostered employee (absences included).';

    public function handle(TenantDatabases $databases, ModuleResolver $modules, Workplace $workplace, Days $days, EmployeeDirectory $directory): int
    {
        $count = 0;
        foreach ([null, ...$databases->names()] as $database) {
            $databases->onConnection($databases->connectionFor($database), function () use ($modules, $workplace, $days, $directory, &$count) {
                $companies = Roster::query()->withoutGlobalScope(OrganizationScope::class)->distinct()->pluck('organization_id');
                foreach (Organization::query()->whereKey($companies->all())->get() as $company) {
                    if (! $modules->isEnabled('attendance', $company)) {
                        continue;
                    }
                    $yesterday = $workplace->today($company)->subDay();
                    $date = $yesterday->toDateString();
                    $ids = $workplace->query(Roster::class, $company)->where('from', '<=', $date)
                        ->where(fn ($query) => $query->whereNull('to')->orWhere('to', '>=', $date))->distinct()->pluck('employee_id')->all();
                    foreach ($directory->many($company, $ids) as $employee) {
                        $count += $days->compute($company, $employee, $yesterday) === null ? 0 : 1;
                    }
                }
            });
        }
        $this->info("Worked out {$count} day(s).");

        return self::SUCCESS;
    }
}
