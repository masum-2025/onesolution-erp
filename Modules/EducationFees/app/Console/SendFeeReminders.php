<?php

namespace Modules\EducationFees\Console;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\EducationFees\Models\Bill;
use Modules\EducationFees\Services\FeeReminders;

/**
 * Every hour: institutions whose rule education_fees.reminders is on, at
 * its hour in their own time zone, remind families of bills due soon or
 * overdue. Each bill's step goes once, so a second run changes nothing.
 */
class SendFeeReminders extends Command
{
    protected $signature = 'education-fees:remind {--now : Ignore the hour (for a first run by hand)}';

    protected $description = 'Send the fee reminders due at this hour.';

    public function handle(TenantDatabases $databases, ModuleResolver $modules, OrganizationSettingsResolver $settings, FeeReminders $reminders): int
    {
        $count = 0;
        foreach ([null, ...$databases->names()] as $database) {
            $databases->onConnection($databases->connectionFor($database), function () use ($modules, $settings, $reminders, &$count) {
                $companies = Bill::query()->withoutGlobalScope(OrganizationScope::class)->where('status', 'open')->distinct()->pluck('organization_id');
                foreach (Organization::query()->whereKey($companies->all())->get() as $company) {
                    $rule = $reminders->rule($company);
                    $hour = (int) CarbonImmutable::now()->setTimezone(($settings->values($company)['timezone'] ?? null) ?: 'UTC')->format('G');
                    if (! $modules->isEnabled('education_fees', $company) || ! $rule['enabled'] || (! $this->option('now') && $hour !== (int) $rule['hour'])) {
                        continue;
                    }
                    $count += $reminders->sendDue($company);
                }
            });
        }
        $this->info("Reminded {$count} bill(s).");

        return self::SUCCESS;
    }
}
