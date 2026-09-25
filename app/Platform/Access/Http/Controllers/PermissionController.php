<?php

namespace App\Platform\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Access\AccessResolver;
use App\Platform\Access\Models\RoleTemplate;
use App\Platform\Access\PermissionCatalog;
use App\Platform\Access\PermissionDefinition;
use App\Platform\Access\Services\RoleService;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * What can go into a role at one organization: permissions grouped by module
 * (with whether this person may grant each one, and why not), the
 * separation-of-duties pairs, and the sector's role templates.
 */
class PermissionController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(
        private PermissionCatalog $catalog,
        private AccessResolver $access,
        private ModuleRegistry $registry,
        private ModuleResolver $modules,
        private RoleService $roles,
    ) {}

    public function index(string $organization): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('viewRoles', $organization);

        $resolved = $this->modules->resolveAll($organization);
        $groups = [];

        foreach ($this->catalog->all() as $permission) {
            $module = $permission->isCore() ? null : $resolved[$permission->moduleKey] ?? null;

            // Modules the plan or sector never offers here are left out entirely.
            if (! $permission->isCore() && ($module === null || ! $module->available)) {
                continue;
            }

            $groups[$permission->group] ??= [
                'group' => $permission->group,
                'label' => $this->groupLabel($permission),
                'module' => $permission->isCore() ? null : $permission->moduleKey,
                'module_enabled' => $permission->isCore() || $module->enabled,
                'permissions' => [],
            ];

            $groups[$permission->group]['permissions'][] = [
                'key' => $permission->key,
                'label' => $permission->label(),
                'blocked_by' => $this->access->grantBlockedBy($permission->key, $organization),
            ];
        }

        // Platform groups first, then modules in use, then modules that are off (stable order).
        $groups = array_values($groups);
        usort($groups, fn (array $a, array $b) => [$a['module'] !== null, ! $a['module_enabled']] <=> [$b['module'] !== null, ! $b['module_enabled']]);

        return response()->json([
            'data' => $groups,
            'separation_of_duties' => $this->access->separationPairs($organization),
        ]);
    }

    public function templates(string $organization): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('viewRoles', $organization);

        return response()->json(['data' => $this->roles->templatesFor($organization)->map(function (RoleTemplate $template) use ($organization) {
            $permissions = $this->roles->templatePermissions($template);

            return [
                'key' => $template->key,
                'sector' => $template->sector_key,
                'name' => $template->label(),
                'description' => $template->description(),
                'permissions' => $permissions,
                // What a clone made by this person would leave out.
                'not_grantable' => array_values(array_filter($permissions, fn (string $key) => ! $this->access->canGrant($key, $organization))),
            ];
        })->values()]);
    }

    private function groupLabel(PermissionDefinition $permission): string
    {
        return $permission->isCore()
            ? __('access.groups.'.$permission->group)
            : $this->registry->get($permission->moduleKey)->label();
    }
}
