<?php

namespace App\Platform\Tenancy\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Exceptions\HierarchyViolation;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads and reshapes the organization tree. All tree queries use the
 * materialized path (portable LIKE 'prefix%'), never recursive SQL.
 */
class HierarchyService
{
    public function __construct(
        private AuditLogger $audit,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private TenantDatabases $databases,
    ) {}

    /**
     * @return Collection<int, Organization> Root first, parent last.
     */
    public function ancestors(Organization $organization): Collection
    {
        $ids = $organization->ancestorIds();

        if ($ids === []) {
            return new Collection;
        }

        return Organization::query()
            ->where('partner_id', $organization->partner_id)
            ->whereKey($ids)
            ->orderBy('depth')
            ->get()
            ->toBase();
    }

    /**
     * @return Collection<int, Organization> Top-down, excluding the organization itself.
     */
    public function descendants(Organization $organization): Collection
    {
        return Organization::query()
            ->subtreeOf($organization)
            ->whereKeyNot($organization->getKey())
            ->orderBy('depth')
            ->get()
            ->toBase();
    }

    public function isAncestorOf(Organization $ancestor, Organization $organization): bool
    {
        return $ancestor->partner_id === $organization->partner_id
            && $ancestor->getKey() !== $organization->getKey()
            && str_starts_with((string) $organization->path, (string) $ancestor->path);
    }

    /**
     * Enforce the partner's structure rule for a type ("root" = no parent).
     */
    public function assertValidParent(OrganizationType $type, ?Organization $parent, Partner $partner): void
    {
        // A personal workspace always stands alone at the top; the structure rule does not apply to it.
        $allowed = $type === OrganizationType::Personal
            ? ['root']
            : $this->rules->get('tenancy.allowed_parents', $this->contexts->forPartner($partner))[$type->value] ?? [];
        $parentType = $parent?->type->value ?? 'root';

        if (! in_array($parentType, $allowed, true)) {
            throw HierarchyViolation::invalidParent($type->value, $parentType);
        }
    }

    public function assertDepthAllowed(int $depth, Partner $partner): void
    {
        $maxDepth = (int) $this->rules->get('tenancy.max_depth', $this->contexts->forPartner($partner));

        if ($depth > $maxDepth) {
            throw HierarchyViolation::tooDeep($maxDepth);
        }
    }

    public function pathFor(string $id, ?Organization $parent): string
    {
        return ($parent?->path ?? '/').$id.'/';
    }

    /**
     * Move an organization (with its whole subtree) under a new parent in the
     * same partner. Paths, depths and root ids are rebuilt; the move is audited.
     */
    public function move(Organization $organization, Organization $newParent, string $reason, ?User $actor = null): Organization
    {
        return DB::transaction(function () use ($organization, $newParent, $reason, $actor) {
            /** @var Organization $organization */
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->getKey());
            /** @var Organization $newParent */
            $newParent = Organization::query()->lockForUpdate()->findOrFail($newParent->getKey());

            if ($newParent->partner_id !== $organization->partner_id) {
                throw HierarchyViolation::crossPartner();
            }

            if ($newParent->is($organization) || $this->isAncestorOf($organization, $newParent)) {
                throw HierarchyViolation::cycle();
            }

            if ($organization->parent_id === $newParent->getKey()) {
                throw HierarchyViolation::alreadyThere();
            }

            // Its business data would stay behind in the old tree's database.
            if ($this->databases->forRoot((string) $organization->root_id) !== $this->databases->forRoot((string) $newParent->root_id)) {
                throw HierarchyViolation::crossDatabase();
            }

            $this->assertValidParent($organization->type, $newParent, $organization->partner);

            $descendants = Organization::query()
                ->subtreeOf($organization)
                ->whereKeyNot($organization->getKey())
                ->lockForUpdate()
                ->orderBy('depth')
                ->get();

            $depthDelta = ($newParent->depth + 1) - $organization->depth;
            $deepest = (int) $descendants->max('depth') ?: $organization->depth;
            $this->assertDepthAllowed(max($deepest, $organization->depth) + $depthDelta, $organization->partner);

            $old = [
                'parent_id' => $organization->parent_id,
                'path' => $organization->path,
            ];
            $oldPath = $organization->path;
            $newPath = $this->pathFor($organization->getKey(), $newParent);

            Organization::allowingTreeWrites(function () use ($organization, $newParent, $descendants, $oldPath, $newPath, $depthDelta) {
                $organization->forceFill([
                    'parent_id' => $newParent->getKey(),
                    'root_id' => $newParent->root_id,
                    'path' => $newPath,
                    'depth' => $newParent->depth + 1,
                ])->save();

                foreach ($descendants as $descendant) {
                    $descendant->forceFill([
                        'root_id' => $newParent->root_id,
                        'path' => $newPath.substr($descendant->path, strlen($oldPath)),
                        'depth' => $descendant->depth + $depthDelta,
                    ])->save();
                }
            });

            $this->audit->record(
                action: 'organization.moved',
                target: $organization,
                old: $old,
                new: [
                    'parent_id' => $organization->parent_id,
                    'path' => $organization->path,
                    'descendants_moved' => $descendants->count(),
                ],
                reason: $reason,
                actor: $actor,
                partnerId: $organization->partner_id,
            );

            return $organization;
        });
    }
}
