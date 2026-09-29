<?php

namespace App\Platform\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Access\Exceptions\AccessException;
use App\Platform\Access\Http\Requests\DeleteRoleRequest;
use App\Platform\Access\Http\Requests\StoreRoleRequest;
use App\Platform\Access\Http\Requests\UpdateRoleRequest;
use App\Platform\Access\Http\RolePresenter;
use App\Platform\Access\Models\Role;
use App\Platform\Access\Services\RoleService;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Roles of one organization: its own and those it inherits from above.
 * Roles are changed only at the organization that owns them.
 */
class RoleController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(private RoleService $roles, private RolePresenter $presenter) {}

    public function index(string $organization): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('viewRoles', $organization);

        $roles = $this->roles->usableAt($organization)->orderBy('created_at')->get();

        return response()->json(['data' => $this->presentAll($roles, $organization)]);
    }

    public function show(string $organization, string $role): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('viewRoles', $organization);

        $role = $this->roles->usableAt($organization)->whereKey($role)->first() ?? throw AccessException::roleNotFound();

        return response()->json(['data' => $this->presentAll(new Collection([$role]), $organization)[0]]);
    }

    public function store(StoreRoleRequest $request, string $organization): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('manageRoles', $organization);

        $role = $this->roles->create(
            $organization,
            (array) $request->validated('name'),
            $request->validated('description'),
            $request->validated('permissions'),
            $request->validated('template_key'),
            $request->user(),
            $request->validated('reason'),
        );

        return response()->json([
            'data' => $this->presentAll(new Collection([$role]), $organization)[0],
            'message' => __('access.messages.role_created'),
        ], 201);
    }

    public function update(UpdateRoleRequest $request, string $organization, string $role): JsonResponse
    {
        [$organization, $role] = $this->ownedRole($organization, $role);

        $role = $this->roles->update(
            $role,
            (int) $request->validated('base_version'),
            array_intersect_key($request->validated(), array_flip(['name', 'description', 'permissions'])),
            $request->user(),
            $request->validated('reason'),
        );

        return response()->json([
            'data' => $this->presentAll(new Collection([$role]), $organization)[0],
            'message' => __('access.messages.role_saved'),
        ]);
    }

    public function destroy(DeleteRoleRequest $request, string $organization, string $role): JsonResponse
    {
        [, $role] = $this->ownedRole($organization, $role);

        $this->roles->delete($role, $request->user(), $request->validated('reason'));

        return response()->json(['message' => __('access.messages.role_deleted')]);
    }

    /**
     * A role is changed only where it is owned; inherited roles are read-only below.
     *
     * @return array{0: Organization, 1: Role}
     */
    private function ownedRole(string $organizationId, string $roleId): array
    {
        $organization = $this->findVisible($organizationId);
        Gate::authorize('manageRoles', $organization);

        $role = Role::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($roleId)
            ->first() ?? throw AccessException::roleNotFound();

        return [$organization, $role];
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @return list<array<string, mixed>>
     */
    private function presentAll(Collection $roles, Organization $organization): array
    {
        $owners = Organization::query()->whereKey($roles->pluck('organization_id')->unique()->values())->get()->keyBy('id');
        $counts = Role::query()->whereKey($roles->modelKeys())->withCount('assignments')->pluck('assignments_count', 'id');

        return $roles->map(fn (Role $role) => $this->presenter->present($role, $organization, $owners, $counts))->values()->all();
    }
}
