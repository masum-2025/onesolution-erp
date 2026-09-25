<?php

namespace App\Platform\Modules;

use App\Platform\Tenancy\Enums\OrganizationStatus;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\LazyCollection;

/**
 * For scheduled tasks: iterate only the organizations where a module is on.
 *
 *     Schedule::call(fn () => app(ModuleScheduler::class)
 *         ->organizationsWithModule('payroll')
 *         ->each(fn ($company) => RunPayroll::dispatch($company->id)))->daily();
 *
 * Jobs should still use EnsureModuleEnabledForJob: the module may be turned
 * off between scheduling and running.
 */
class ModuleScheduler
{
    public function __construct(private ModuleResolver $resolver) {}

    /**
     * @param  list<OrganizationType>  $types
     * @return LazyCollection<int, Organization>
     */
    public function organizationsWithModule(string $moduleKey, array $types = [OrganizationType::Company]): LazyCollection
    {
        return Organization::query()
            ->whereIn('type', $types)
            ->where('status', OrganizationStatus::Active)
            ->lazyById()
            ->filter(fn (Organization $organization) => $this->resolver->isEnabled($moduleKey, $organization));
    }
}
