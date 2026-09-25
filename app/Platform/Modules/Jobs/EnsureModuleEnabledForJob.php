<?php

namespace App\Platform\Modules\Jobs;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Job middleware. A module job returns it from middleware():
 *
 *     public function middleware(): array
 *     {
 *         return [new EnsureModuleEnabledForJob('payroll', $this->organizationId)];
 *     }
 *
 * When the module is off (or the organization is gone) the job is skipped,
 * not failed and not retried.
 */
class EnsureModuleEnabledForJob
{
    public function __construct(
        public string $moduleKey,
        public string $organizationId,
    ) {}

    public function handle(object $job, Closure $next): void
    {
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null || ! app(ModuleResolver::class)->isEnabled($this->moduleKey, $organization)) {
            Log::info('Job skipped because its module is disabled.', [
                'job' => $job::class,
                'module' => $this->moduleKey,
                'organization_id' => $this->organizationId,
            ]);

            return;
        }

        $next($job);
    }
}
