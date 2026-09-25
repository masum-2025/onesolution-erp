<?php

namespace App\Platform\Tenancy\Policies;

use App\Models\User;
use App\Platform\Access\AccessResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Auth\Access\Response;

/**
 * Visibility is enforced by the lookup (Organization::visibleTo). On top of
 * it: owners and staff read the structure (portal users never do), and every
 * change needs a permission that reaches the target (AccessResolver).
 */
class OrganizationPolicy
{
    public function __construct(private CurrentContext $context, private AccessResolver $access) {}

    public function viewAny(User $user): Response
    {
        return $this->allowIf($this->isActingUser($user) && ! $this->access->isPortal());
    }

    public function view(User $user, Organization $organization): Response
    {
        return $this->viewAny($user);
    }

    public function create(User $user, Organization $parent): Response
    {
        return $this->permits($user, 'organizations.manage', $parent);
    }

    public function update(User $user, Organization $organization): Response
    {
        return $this->permits($user, 'organizations.manage', $organization);
    }

    public function move(User $user, Organization $organization, ?Organization $newParent = null): Response
    {
        return $this->allowIf(
            $this->permits($user, 'organizations.move', $organization)->allowed()
            && ($newParent === null || $this->access->allows('organizations.move', $newParent)),
        );
    }

    public function manageMembers(User $user, Organization $organization): Response
    {
        return $this->permits($user, 'members.manage', $organization);
    }

    /**
     * Making someone an owner, or changing an owner's membership, is for owners only.
     */
    public function manageOwnership(User $user, Organization $organization): Response
    {
        return $this->allowIf($this->permits($user, 'members.manage', $organization)->allowed() && $this->access->isOwner());
    }

    public function manageRoles(User $user, Organization $organization): Response
    {
        return $this->permits($user, 'roles.manage', $organization);
    }

    /**
     * Seeing roles and permissions: needed to define roles or to give them.
     */
    public function viewRoles(User $user, Organization $organization): Response
    {
        return $this->allowIf($this->isActingUser($user) && (
            $this->access->allows('roles.manage', $organization) || $this->access->allows('members.manage', $organization)
        ));
    }

    private function permits(User $user, string $permission, Organization $organization): Response
    {
        return $this->allowIf($this->isActingUser($user) && $this->access->allows($permission, $organization));
    }

    private function isActingUser(User $user): bool
    {
        return $this->context->hasOrganization() && $this->context->user()?->is($user) === true;
    }

    private function allowIf(bool $allowed): Response
    {
        return $allowed ? Response::allow() : Response::deny(__('tenancy.errors.forbidden'));
    }
}
