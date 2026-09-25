<?php

namespace App\Platform\Modules\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Modules\Http\Requests\ReasonRequest;
use App\Platform\Modules\Http\Requests\ToggleModuleRequest;
use App\Platform\Modules\Http\Resources\ModuleStatusResource;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Modules\Services\ModuleToggleService;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Services\HierarchyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ModuleController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(
        private ModuleRegistry $registry,
        private ModuleResolver $resolver,
        private ModuleToggleService $toggles,
    ) {}

    /**
     * Every module with its effective state for this organization and where it comes from.
     */
    public function index(string $organization, HierarchyService $hierarchy): AnonymousResourceCollection
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('view', $organization);

        $names = $hierarchy->ancestors($organization)
            ->concat([$organization])
            ->mapWithKeys(fn (Organization $node) => [$node->getKey() => $node->displayName()])
            ->all();

        $resolved = $this->resolver->resolveAll($organization);

        return ModuleStatusResource::collection(array_map(
            fn (string $key) => [
                'module' => $this->registry->get($key),
                'resolved' => $resolved[$key],
                'names' => $names,
            ],
            $this->registry->keys(),
        ));
    }

    public function enable(ToggleModuleRequest $request, string $organization, string $module): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('modules.manage', $organization);

        $autoEnabled = $this->toggles->enable(
            $organization,
            $module,
            $request->validated('reason'),
            $request->boolean('lock'),
            $request->user(),
        );

        return $this->state($organization, $module, ['auto_enabled' => $autoEnabled]);
    }

    public function disable(ToggleModuleRequest $request, string $organization, string $module): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('modules.manage', $organization);

        $alsoDisabled = $this->toggles->disable(
            $organization,
            $module,
            $request->validated('reason'),
            $request->boolean('lock'),
            $request->boolean('confirm'),
            $request->user(),
        );

        return $this->state($organization, $module, ['also_disabled' => $alsoDisabled]);
    }

    public function inherit(ReasonRequest $request, string $organization, string $module): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('modules.manage', $organization);

        $this->toggles->inherit($organization, $module, $request->validated('reason'), $request->user());

        return $this->state($organization, $module);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function state(Organization $organization, string $key, array $extra = []): JsonResponse
    {
        $resolved = $this->resolver->resolve($key, $organization);

        return response()->json([
            'data' => [
                'key' => $key,
                'enabled' => $resolved->enabled,
                'reason' => $resolved->reason->value,
                'state' => $resolved->state->value,
                'locked_here' => $resolved->lockedHere,
                ...$extra,
            ],
        ]);
    }
}
