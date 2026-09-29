<?php

namespace App\Platform\Access;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * What the acting membership may do. A permission works on a target only when
 * all three hold:
 *
 *  1. the membership holds it: an owner holds every permission except those in
 *     a separation-of-duties pair; everyone else holds what their roles give;
 *  2. the target is the membership's own organization or a unit below it
 *     (reading may reach further, e.g. a branch member reads the company);
 *  3. the permission's module is enabled at the target.
 *
 * Portal memberships (parents, customers, patients) hold nothing here; their
 * own-records access arrives with Phase 5C. Scoped per request or job.
 */
class AccessResolver
{
    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(
        private CurrentContext $context,
        private PermissionCatalog $catalog,
        private ModuleResolver $modules,
        private RuleResolver $rules,
        private RuleContextFactory $ruleContexts,
    ) {}

    /**
     * Roles held by the acting membership, in a stable order.
     *
     * @return list<string>
     */
    public function roleIds(): array
    {
        if (! $this->context->hasOrganization() || $this->context->membership()->membership_type === MembershipType::Portal) {
            return [];
        }

        $membershipId = $this->context->membership()->getKey();

        return $this->memo['roles:'.$membershipId] ??= DB::table('membership_roles')
            ->where('membership_id', $membershipId)
            ->orderBy('role_id')
            ->pluck('role_id')
            ->all();
    }

    /**
     * Every permission the acting membership holds, whatever the target.
     *
     * @return list<string>
     */
    public function held(): array
    {
        if (! $this->context->hasOrganization()) {
            return [];
        }

        return $this->memo['held:'.$this->context->membership()->getKey()] ??= $this->computeHeld();
    }

    public function holds(string $permission): bool
    {
        return in_array($permission, $this->held(), true);
    }

    /**
     * Permissions that work in the current organization (module enabled there).
     * This is what the UI receives; endpoints still check allows().
     *
     * @return list<string>
     */
    public function effective(): array
    {
        if (! $this->context->hasOrganization()) {
            return [];
        }

        $organization = $this->context->organization();

        return array_values(array_filter($this->held(), fn (string $key) => $this->moduleEnabled($key, $organization)));
    }

    /**
     * The full check: held, target in reach, module enabled at the target.
     *
     * $checkModule = false lets a service report a disabled module with its own,
     * more specific message (e.g. rule edits); the request is still refused.
     */
    public function allows(string $permission, Organization $target, bool $checkModule = true): bool
    {
        return $this->context->hasOrganization()
            && $this->holds($permission)
            && $this->manages($target)
            && (! $checkModule || $this->moduleEnabled($permission, $target));
    }

    /**
     * Whether the target is the acting organization or below it. A branch
     * admin reaches their branch and its departments, not the company.
     */
    public function manages(Organization $target): bool
    {
        if (! $this->context->hasOrganization()) {
            return false;
        }

        $own = $this->context->organization();

        return $target->partner_id === $own->partner_id && str_starts_with((string) $target->path, (string) $own->path);
    }

    public function isOwner(): bool
    {
        return $this->context->hasOrganization() && $this->context->membership()->isOwner();
    }

    public function isPortal(): bool
    {
        return $this->context->hasOrganization() && $this->context->membership()->membership_type === MembershipType::Portal;
    }

    /**
     * Whether the acting membership may put this permission into a role or
     * give it to someone at an organization. Nobody grants what they do not
     * hold; an owner may grant everything within their unit, including both
     * sides of a separation-of-duties pair (to different people).
     *
     * @return 'unknown'|'module_disabled'|'not_held'|null Null when grantable.
     */
    public function grantBlockedBy(string $permission, Organization $at): ?string
    {
        return match (true) {
            ! $this->catalog->has($permission) => 'unknown',
            ! $this->moduleEnabled($permission, $at) => 'module_disabled',
            ! $this->isOwner() && ! $this->holds($permission) => 'not_held',
            default => null,
        };
    }

    public function canGrant(string $permission, Organization $at): bool
    {
        return $this->grantBlockedBy($permission, $at) === null;
    }

    public function moduleEnabled(string $permission, Organization $organization): bool
    {
        if (! $this->catalog->has($permission)) {
            return false;
        }

        $module = $this->catalog->get($permission)->moduleKey;

        return $module === PermissionCatalog::CORE_MODULE || $this->modules->isEnabled($module, $organization);
    }

    /**
     * Pairs one person may not hold together, as configured for an
     * organization (rule access.separation_of_duties).
     *
     * @return list<array{first: string, second: string}>
     */
    public function separationPairs(Organization $organization): array
    {
        $pairs = $this->rules->get('access.separation_of_duties', $this->ruleContexts->forOrganization($organization));

        return array_values(array_filter(
            (array) $pairs,
            fn ($pair) => is_array($pair) && isset($pair['first'], $pair['second']) && $pair['first'] !== $pair['second'],
        ));
    }

    /**
     * Pairs that a set of permissions breaks.
     *
     * @param  list<string>  $permissions
     * @return list<array{first: string, second: string}>
     */
    public function conflicts(array $permissions, Organization $organization): array
    {
        $held = array_flip($permissions);

        return array_values(array_filter(
            $this->separationPairs($organization),
            fn (array $pair) => isset($held[$pair['first']], $held[$pair['second']]),
        ));
    }

    /**
     * Drop memoized answers (after roles or assignments change).
     */
    public function forget(): void
    {
        $this->memo = [];
    }

    /**
     * @return list<string>
     */
    private function computeHeld(): array
    {
        $membership = $this->context->membership();

        if ($membership->membership_type === MembershipType::Portal) {
            return [];
        }

        $organization = $this->context->organization();
        $pairs = $this->separationPairs($organization);
        $paired = array_unique(array_merge(array_column($pairs, 'first'), array_column($pairs, 'second')));

        // Roles only count where they are usable: owned by this organization or an ancestor.
        $fromRoles = $this->roleIds() === [] ? [] : DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->whereIn('role_permissions.role_id', $this->roleIds())
            ->whereIn('roles.organization_id', [...$organization->ancestorIds(), $organization->getKey()])
            ->pluck('role_permissions.permission_key')
            ->all();

        $implicit = $membership->isOwner() ? array_diff($this->catalog->keys(), $paired) : [];

        $held = array_values(array_intersect(
            array_unique([...$implicit, ...$fromRoles]),
            $this->catalog->keys(),
        ));

        // Fail closed: if a pair was added after roles were given, neither side works
        // until an administrator fixes the roles.
        foreach ($this->conflicts($held, $organization) as $pair) {
            $held = array_values(array_diff($held, [$pair['first'], $pair['second']]));
        }

        sort($held);

        return $held;
    }
}
