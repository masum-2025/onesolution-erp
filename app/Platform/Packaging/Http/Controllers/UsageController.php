<?php

namespace App\Platform\Packaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Packaging\Http\PackageSummary;
use App\Platform\Packaging\Http\PlanPresenter;
use App\Platform\Packaging\Models\OrganizationPackage;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Packaging\Services\UsageLimiter;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The subscription an organization belongs to: plan, limits and how much is
 * used (counted over the whole tree), modules only a higher plan has, and
 * the sector package the company started with.
 */
class UsageController extends Controller
{
    use FindsVisibleOrganizations;

    public function __invoke(
        string $organization,
        UsageLimiter $limiter,
        PlanCatalog $plans,
        ModuleRegistry $registry,
        PlanPresenter $presenter,
        PackageSummary $summary,
    ): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('view', $organization);

        $root = $limiter->root($organization);
        $planKey = $root->plan_key ?? config('tenancy.defaults.plan_key');
        $plan = $plans->has($planKey) ? $plans->get($planKey) : null;
        $limits = $limiter->limits($root);
        $usage = $limiter->usage($root);

        $notIncluded = array_values(array_filter(
            $registry->keys(),
            fn (string $key) => ! $registry->get($key)->isCore && ! $plans->includes($planKey, $key),
        ));

        $package = OrganizationPackage::query()->where('organization_id', $organization->getKey())->latest()->first();

        return response()->json(['data' => [
            'plan' => ['key' => $planKey, 'name' => $plan?->label() ?? $planKey],
            'subscription' => ['id' => $root->getKey(), 'name' => $root->displayName()],
            'limits' => array_map(
                fn (string $limit) => [
                    'limit' => $limit,
                    'max' => $limits[$limit],
                    'used' => $usage[$limit],
                    // Where to go when it is (nearly) full.
                    'upgrade' => $limits[$limit] === null ? [] : $limiter->upgradesFor($limit, ($usage[$limit] ?? 0) + 1, $planKey),
                ],
                array_keys(UsageLimiter::LIMITS),
            ),
            'modules_not_included' => $presenter->modules($notIncluded),
            'package' => $package === null ? null : $summary->for($package),
        ]]);
    }
}
