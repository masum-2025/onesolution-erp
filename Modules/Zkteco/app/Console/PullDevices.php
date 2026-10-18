<?php

namespace Modules\Zkteco\Console;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Exceptions\TenancyException;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Modules\Zkteco\Models\Device;
use Modules\Zkteco\Services\DeviceSync;

/**
 * Pull every active terminal that is due (rule zkteco.pull_minutes, read at
 * its branch), in every client database, where ZKTeco and attendance are on.
 * Runs each minute from the scheduler; on a cloud server it runs instead on
 * an office computer that sees the terminals. --device pulls one now;
 * --user keeps one terminal user's logs (a first try on a real device).
 */
class PullDevices extends Command
{
    protected $signature = 'zkteco:pull {--device= : Pull this device now, whether due or not} {--user= : Keep only this terminal user id\'s logs}';

    protected $description = 'Bring ZKTeco terminals\' punches into staff attendance.';

    public function handle(TenantDatabases $databases, ModuleResolver $modules, DeviceSync $sync, RuleResolver $rules, RuleContextFactory $contexts): int
    {
        $only = $this->option('device');
        $user = $this->option('user');
        $failed = 0;
        foreach ([null, ...$databases->names()] as $database) {
            $databases->onConnection($databases->connectionFor($database), function () use ($modules, $sync, $rules, $contexts, $only, $user, &$failed) {
                $devices = Device::query()->withoutGlobalScope(OrganizationScope::class)->where('is_active', true)
                    ->when($only !== null, fn ($query) => $query->whereKey($only))->get();
                foreach ($devices->groupBy('organization_id') as $companyId => $list) {
                    $company = Organization::query()->find($companyId);
                    if ($company === null || ! $modules->isEnabled('zkteco', $company) || ! $modules->isEnabled('attendance', $company)) {
                        continue;
                    }
                    foreach ($list as $device) {
                        $branch = Organization::query()->find($device->unit_id) ?? $company;
                        $every = (int) $rules->get('zkteco.pull_minutes', $contexts->forOrganization($branch));
                        if ($only === null && $device->last_attempt_at !== null && $device->last_attempt_at->greaterThan(now()->subMinutes($every)->addSeconds(5))) {
                            continue;
                        }
                        try {
                            $result = $sync->pull($company, $device, null, $user);
                            $this->line("{$device->name}: read {$result['read']}, new {$result['new']}, recorded {$result['recorded']}, unmatched {$result['unmatched']}, not employed {$result['not_employed']}");
                        } catch (TenancyException $exception) {
                            $failed++;
                            $this->warn("{$device->name}: {$exception->userMessage()}");
                        }
                    }
                }
            });
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
