<?php

namespace App\Platform\Access\Services;

use App\Models\User;
use App\Platform\Access\AccessResolver;
use App\Platform\Access\Exceptions\AccessException;
use App\Platform\Access\Models\MembershipRole;
use App\Platform\Access\Models\Role;
use App\Platform\Access\Models\RoleTemplate;
use App\Platform\Access\PermissionCatalog;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every write to roles and role assignments goes through here: anti-escalation
 * (nobody grants what they do not hold), separation of duties, optimistic
 * locking, audit. Callers check the permission and reach (policies) first.
 */
class RoleService
{
    public function __construct(
        private AccessResolver $access,
        private PermissionCatalog $catalog,
        private AuditLogger $audit,
    ) {}

    /**
     * Roles usable at an organization: its own and those of every ancestor.
     *
     * @return Builder<Role>
     */
    public function usableAt(Organization $organization): Builder
    {
        return Role::query()
            ->whereIn('organization_id', [...$organization->ancestorIds(), $organization->getKey()]);
    }

    /**
     * Templates for the organization's sector (nearest sector in the chain),
     * plus the ones for every sector.
     *
     * @return Collection<int, RoleTemplate>
     */
    public function templatesFor(Organization $organization): Collection
    {
        $sector = $this->sectorOf($organization);

        return RoleTemplate::query()
            ->whereNull('deprecated_at')
            ->where(fn ($query) => $query->whereNull('sector_key')->when($sector !== null, fn ($q) => $q->orWhere('sector_key', $sector)))
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @return list<string>
     */
    public function templatePermissions(RoleTemplate $template): array
    {
        return $this->catalog->expand((array) $template->permissions);
    }

    /**
     * @param  array<string, string|null>  $name
     * @param  array<string, string|null>|null  $description
     * @param  list<string>|null  $permissions  Null with a template: the template's grantable permissions.
     */
    public function create(
        Organization $organization,
        array $name,
        ?array $description,
        ?array $permissions,
        ?string $templateKey,
        User $actor,
        ?string $reason = null,
    ): Role {
        $template = null;

        if ($templateKey !== null) {
            $template = $this->templatesFor($organization)->firstWhere('key', $templateKey)
                ?? throw AccessException::templateNotFound();
        }

        if ($permissions === null) {
            // Cloning: keep what this person may grant here; the rest can be added later by someone who holds it.
            $permissions = array_values(array_filter(
                $template === null ? [] : $this->templatePermissions($template),
                fn (string $key) => $this->access->canGrant($key, $organization),
            ));
        }

        $permissions = $this->normalize($permissions);
        $this->assertGrantable($permissions, $organization);
        $this->assertNoConflict($permissions, $organization);

        return $this->store($organization, $name, $description, $permissions, $template?->key, $actor, $reason);
    }

    /**
     * Onboarding only (ApplySectorPackage): a new company has no administrator
     * yet, so nobody's own permissions can bound the grant. Separation of
     * duties still applies. Names come from the template in every language.
     */
    public function createFromTemplateForPackage(Organization $organization, RoleTemplate $template, ?User $actor, string $reason): Role
    {
        $permissions = $this->normalize($this->templatePermissions($template));
        $this->assertNoConflict($permissions, $organization);

        $locales = (array) config('tenancy.supported_locales');
        $name = array_combine($locales, array_map(fn (string $locale) => $template->label($locale), $locales));
        $description = array_combine($locales, array_map(fn (string $locale) => $template->description($locale), $locales));

        return $this->store($organization, $name, $description, $permissions, $template->key, $actor, $reason);
    }

    /**
     * @param  array<string, string|null>  $name
     * @param  array<string, string|null>|null  $description
     * @param  list<string>  $permissions  Already checked.
     */
    private function store(Organization $organization, array $name, ?array $description, array $permissions, ?string $templateKey, ?User $actor, ?string $reason): Role
    {
        return DB::transaction(function () use ($organization, $name, $description, $permissions, $templateKey, $actor, $reason) {
            $role = Role::create([
                'organization_id' => $organization->getKey(),
                'key' => $this->uniqueKey($organization, $name, $templateKey),
                'name' => $this->cleanTexts($name),
                'description' => $description === null ? null : ($this->cleanTexts($description) ?: null),
                'template_key' => $templateKey,
                'version' => 1,
                'created_by' => $actor?->getKey(),
            ]);

            $this->writePermissions($role, $permissions);

            $this->audit->record(
                action: 'role.created',
                target: $role,
                new: ['name' => $role->texts('name'), 'template' => $role->template_key, 'permissions' => $permissions],
                reason: $reason,
                actor: $actor,
                organizationId: $organization->getKey(),
                partnerId: $organization->partner_id,
            );

            $this->access->forget();

            return $role;
        });
    }

    /**
     * @param  array{name?: array<string, string|null>, description?: array<string, string|null>|null, permissions?: list<string>}  $changes
     */
    public function update(Role $role, int $baseVersion, array $changes, User $actor, string $reason): Role
    {
        $organization = $role->organization;

        return DB::transaction(function () use ($role, $baseVersion, $changes, $actor, $reason, $organization) {
            $locked = Role::query()->whereKey($role->getKey())->lockForUpdate()->first() ?? throw AccessException::roleNotFound();

            if ($locked->version !== $baseVersion) {
                throw AccessException::versionConflict($locked->version);
            }

            $oldPermissions = $locked->permissionKeys();
            $old = [];
            $new = [];

            if (array_key_exists('permissions', $changes)) {
                $permissions = $this->normalize($changes['permissions']);
                // Keeping what is already there is fine; adding needs the right to grant it.
                $this->assertGrantable(array_values(array_diff($permissions, $oldPermissions)), $organization);
                $this->assertNoConflict($permissions, $organization);
                $this->assertMembersStaySeparated($locked, $permissions);

                if ($permissions !== $oldPermissions) {
                    // Removing needs the same right: a branch admin cannot strip what they could not give.
                    $this->assertGrantable(array_values(array_diff($oldPermissions, $permissions)), $organization, ignoreModule: true);
                    $this->writePermissions($locked, $permissions);
                    $old['permissions'] = $oldPermissions;
                    $new['permissions'] = $permissions;
                }
            }

            if (array_key_exists('name', $changes)) {
                $name = $this->cleanTexts((array) $changes['name']);
                if ($name !== $locked->texts('name')) {
                    [$old['name'], $new['name']] = [$locked->texts('name'), $name];
                    $locked->putTexts('name', $name);
                }
            }

            if (array_key_exists('description', $changes)) {
                $description = $changes['description'] === null ? null : ($this->cleanTexts($changes['description']) ?: null);
                $current = $locked->texts('description') ?: null;
                if ($description !== $current) {
                    [$old['description'], $new['description']] = [$current, $description];
                    $locked->putTexts('description', $description);
                }
            }

            if ($new === []) {
                return $locked;
            }

            $locked->version++;
            $locked->save();

            $this->audit->record(
                action: 'role.updated',
                target: $locked,
                old: $old,
                new: $new,
                reason: $reason,
                actor: $actor,
                organizationId: $organization->getKey(),
                partnerId: $organization->partner_id,
            );

            $this->access->forget();

            return $locked;
        });
    }

    /**
     * A role someone still holds cannot be deleted (turning things off never loses data silently).
     */
    public function delete(Role $role, User $actor, string $reason): void
    {
        $organization = $role->organization;

        DB::transaction(function () use ($role, $actor, $reason, $organization) {
            $members = $role->assignments()->count();

            if ($members > 0) {
                throw AccessException::roleInUse($members);
            }

            $permissions = $role->permissionKeys();
            $this->assertGrantable($permissions, $organization, ignoreModule: true);

            $this->audit->record(
                action: 'role.deleted',
                target: $role,
                old: ['name' => $role->texts('name'), 'permissions' => $permissions],
                reason: $reason,
                actor: $actor,
                organizationId: $organization->getKey(),
                partnerId: $organization->partner_id,
            );

            $role->delete();
            $this->access->forget();
        });
    }

    /**
     * Replace the roles one membership holds.
     *
     * @param  list<string>  $roleIds
     * @return Collection<int, Role>
     */
    public function syncMembershipRoles(OrganizationMembership $membership, array $roleIds, User $actor, string $reason): Collection
    {
        if ($membership->user_id === $actor->getKey()) {
            throw AccessException::ownRoles();
        }

        $roleIds = array_values(array_unique($roleIds));
        $organization = $membership->organization;

        if ($roleIds !== [] && $membership->membership_type === MembershipType::Portal) {
            throw AccessException::portalMembership();
        }

        $roles = $this->usableAt($organization)->whereKey($roleIds)->get();

        if ($roles->count() !== count($roleIds)) {
            throw AccessException::roleNotUsable();
        }

        return DB::transaction(function () use ($membership, $roles, $roleIds, $actor, $reason, $organization) {
            $current = MembershipRole::query()
                ->where('membership_id', $membership->getKey())
                ->lockForUpdate()
                ->pluck('role_id')
                ->all();

            $added = array_values(array_diff($roleIds, $current));
            $removed = array_values(array_diff($current, $roleIds));

            if ($added === [] && $removed === []) {
                return $roles;
            }

            // Giving or taking away a role needs the right to grant everything in it.
            $touched = Role::query()->whereKey([...$added, ...$removed])->get();
            $this->assertGrantable(
                array_values(array_unique($touched->flatMap(fn (Role $role) => $role->permissionKeys())->all())),
                $organization,
                ignoreModule: true,
            );

            $this->assertNoConflict(
                array_values(array_unique($roles->flatMap(fn (Role $role) => $role->permissionKeys())->all())),
                $organization,
            );

            MembershipRole::query()->where('membership_id', $membership->getKey())->whereIn('role_id', $removed)->delete();

            foreach ($added as $roleId) {
                MembershipRole::create([
                    'organization_id' => $organization->getKey(),
                    'membership_id' => $membership->getKey(),
                    'role_id' => $roleId,
                    'assigned_by' => $actor->getKey(),
                ]);
            }

            $keys = Role::query()->whereKey([...$current, ...$roleIds])->pluck('key', 'id');

            $this->audit->record(
                action: 'membership.roles_changed',
                target: $membership,
                old: ['roles' => array_map(fn (string $id) => $keys[$id] ?? $id, $current)],
                new: ['roles' => array_map(fn (string $id) => $keys[$id] ?? $id, $roleIds)],
                reason: $reason,
                actor: $actor,
                organizationId: $organization->getKey(),
                partnerId: $organization->partner_id,
            );

            $this->access->forget();

            return $roles;
        });
    }

    public function sectorOf(Organization $organization): ?string
    {
        if ($organization->sector_key !== null) {
            return $organization->sector_key;
        }

        return Organization::query()
            ->whereKey($organization->ancestorIds())
            ->whereNotNull('sector_key')
            ->orderByDesc('depth')
            ->value('sector_key');
    }

    /**
     * @param  list<string>  $permissions
     */
    private function assertGrantable(array $permissions, Organization $organization, bool $ignoreModule = false): void
    {
        $blocked = [];
        foreach ($permissions as $key) {
            $reason = $this->access->grantBlockedBy($key, $organization);
            if ($reason !== null && ! ($ignoreModule && $reason === 'module_disabled')) {
                $blocked[$reason][] = $key;
            }
        }

        if (isset($blocked['unknown']) || isset($blocked['not_held'])) {
            throw AccessException::cannotGrant([...($blocked['unknown'] ?? []), ...($blocked['not_held'] ?? [])]);
        }

        if (isset($blocked['module_disabled'])) {
            throw AccessException::moduleDisabled($blocked['module_disabled']);
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    private function assertNoConflict(array $permissions, Organization $organization): void
    {
        $conflicts = $this->access->conflicts($permissions, $organization);

        if ($conflicts !== []) {
            throw AccessException::separationOfDuties($conflicts);
        }
    }

    /**
     * Changing a role must not give any holder both sides of a pair through
     * their other roles.
     *
     * @param  list<string>  $permissions
     */
    private function assertMembersStaySeparated(Role $role, array $permissions): void
    {
        $assignments = MembershipRole::query()->where('role_id', $role->getKey())->with('membership.organization')->get();

        $broken = [];
        $count = 0;

        foreach ($assignments as $assignment) {
            $others = Role::query()
                ->whereIn('id', MembershipRole::query()
                    ->where('membership_id', $assignment->membership_id)
                    ->where('role_id', '!=', $role->getKey())
                    ->select('role_id'))
                ->get()
                ->flatMap(fn (Role $other) => $other->permissionKeys())
                ->all();

            $conflicts = $this->access->conflicts(array_values(array_unique([...$others, ...$permissions])), $assignment->membership->organization);

            if ($conflicts !== []) {
                $broken = $broken ?: $conflicts;
                $count++;
            }
        }

        if ($count > 0) {
            throw AccessException::separationOfDutiesForMembers($broken, $count);
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    private function writePermissions(Role $role, array $permissions): void
    {
        DB::table('role_permissions')->where('role_id', $role->getKey())->delete();
        DB::table('role_permissions')->insert(array_map(
            fn (string $key) => ['role_id' => $role->getKey(), 'permission_key' => $key],
            $permissions,
        ));
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function normalize(array $permissions): array
    {
        $permissions = array_values(array_unique(array_map('strval', $permissions)));
        sort($permissions);

        return $permissions;
    }

    /**
     * Keep only supported locales with text.
     *
     * @param  array<string, string|null>  $texts
     * @return array<string, string>
     */
    private function cleanTexts(array $texts): array
    {
        $clean = [];
        foreach ((array) config('tenancy.supported_locales') as $locale) {
            $text = trim((string) ($texts[$locale] ?? ''));
            if ($text !== '') {
                $clean[$locale] = $text;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, string|null>  $name
     */
    private function uniqueKey(Organization $organization, array $name, ?string $templateKey): string
    {
        // A Bangla-only name slugs to nothing; fall back to the template or "role".
        $base = Str::slug((string) ($name['en'] ?? ''), '_') ?: Str::slug((string) $templateKey, '_') ?: 'role';
        $base = Str::limit($base, 50, '');
        $key = $base;

        for ($i = 2; Role::query()->where('organization_id', $organization->getKey())->where('key', $key)->exists(); $i++) {
            $key = $base.'_'.$i;
        }

        return $key;
    }
}
