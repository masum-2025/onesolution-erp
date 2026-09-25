<?php

namespace App\Platform\Tenancy\Policies;

use App\Models\User;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Auth\Access\Response;

/**
 * Interim authorization until Phase 4 permissions: visibility is enforced by
 * the lookup (Organization::visibleTo), and only an "owner" membership in the
 * active context may change the tree or its members.
 */
class OrganizationPolicy
{
    public function __construct(private CurrentContext $context) {}

    public function view(User $user, Organization $organization): Response
    {
        return $this->allowIf($this->isActingUser($user));
    }

    public function create(User $user, Organization $parent): Response
    {
        return $this->manage($user);
    }

    public function update(User $user, Organization $organization): Response
    {
        return $this->manage($user);
    }

    public function move(User $user, Organization $organization): Response
    {
        return $this->manage($user);
    }

    public function manageMembers(User $user, Organization $organization): Response
    {
        return $this->manage($user);
    }

    private function manage(User $user): Response
    {
        return $this->allowIf($this->isActingUser($user) && $this->context->membership()->isOwner());
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
