<?php

namespace App\Platform\Access\Http;

use App\Platform\Access\AccessResolver;
use App\Platform\Access\Models\Role;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;

/**
 * Shapes a role for the roles screen and the member role dialog: where it
 * comes from, whether this person may edit it or give it, and why not.
 */
class RolePresenter
{
    public function __construct(private AccessResolver $access) {}

    /**
     * @param  Collection<string, Organization>  $owners  Owning organizations by id.
     * @param  Collection<string, int>  $memberCounts  Assignments by role id.
     * @return array<string, mixed>
     */
    public function present(Role $role, Organization $viewedAt, Collection $owners, Collection $memberCounts): array
    {
        $permissions = $role->permissionKeys();
        $owner = $owners->get($role->organization_id);
        $notAssignable = array_values(array_filter($permissions, fn (string $key) => $this->access->grantBlockedBy($key, $viewedAt) === 'not_held'));

        return [
            'id' => $role->getKey(),
            'key' => $role->key,
            'name' => $role->displayName(),
            'names' => (object) ((array) $role->name),
            'description' => $role->displayDescription(),
            'descriptions' => (object) ((array) ($role->description ?? [])),
            'template_key' => $role->template_key,
            'version' => $role->version,
            'permissions' => $permissions,
            'organization' => $owner === null ? null : [
                'id' => $owner->getKey(),
                'name' => $owner->displayName(),
                'type' => $owner->type->value,
            ],
            'owned_here' => $role->organization_id === $viewedAt->getKey(),
            'editable' => $owner !== null && $this->access->allows('roles.manage', $owner),
            // Giving or removing the role needs every permission in it.
            'assignable' => $notAssignable === [],
            'not_assignable_permissions' => $notAssignable,
            'members_count' => (int) $memberCounts->get($role->getKey(), 0),
            'updated_at' => $role->updated_at?->toIso8601String(),
        ];
    }
}
