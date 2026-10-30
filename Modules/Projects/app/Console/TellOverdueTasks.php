<?php

namespace Modules\Projects\Console;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Console\Command;
use Modules\Projects\Models\Project;
use Modules\Projects\Services\Tasks;

/** Daily: tell each task's people when it has passed its due day (once per due day), in every client database. */
class TellOverdueTasks extends Command
{
    protected $signature = 'projects:overdue-tasks';

    protected $description = 'Tell the people of tasks past their due day';

    public function handle(TenantDatabases $databases, ModuleResolver $modules, Tasks $tasks): int
    {
        $count = 0;
        foreach ([null, ...$databases->names()] as $database) {
            $databases->onConnection($databases->connectionFor($database), function () use ($modules, $tasks, &$count) {
                $companies = Project::query()->withoutGlobalScope(OrganizationScope::class)->whereIn('status', Project::OPEN)->distinct()->pluck('company_id');
                foreach (Organization::query()->whereKey($companies->all())->get() as $company) {
                    if ($modules->isEnabled('projects', $company)) {
                        $count += $tasks->tellOverdue($company);
                    }
                }
            });
        }
        $this->info("Told about {$count} task(s).");

        return self::SUCCESS;
    }
}
