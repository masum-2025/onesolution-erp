<?php

namespace App\Platform\Tenancy\Actions;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Packaging\Services\UsageLimiter;
use App\Platform\Tenancy\Enums\OrganizationStatus;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use App\Platform\Tenancy\Services\HierarchyService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates an organization and places it in the tree. A root (group) needs a
 * partner; a child always takes its partner from the parent.
 */
class CreateOrganization
{
    public function __construct(
        private HierarchyService $hierarchy,
        private AuditLogger $audit,
        private UsageLimiter $limits,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  Validated, fillable attributes only.
     */
    public function handle(
        OrganizationType $type,
        array $attributes,
        ?Organization $parent = null,
        ?Partner $partner = null,
        ?User $actor = null,
    ): Organization {
        if ($parent === null && $partner === null) {
            throw new InvalidArgumentException('A root organization needs a partner.');
        }

        $owningPartner = $partner ?? $parent->partner;
        $this->hierarchy->assertValidParent($type, $parent, $owningPartner);

        $depth = $parent ? $parent->depth + 1 : 0;
        $this->hierarchy->assertDepthAllowed($depth, $owningPartner);

        return DB::transaction(function () use ($type, $attributes, $parent, $partner, $depth, $actor) {
            // Plan limit on branches, counted over the whole subscription.
            if ($type === OrganizationType::Branch && $parent !== null) {
                $this->limits->assertBranchAvailable($parent);
            }

            $organization = new Organization;
            $id = $organization->newUniqueId();

            $organization->fill($attributes);
            $organization->forceFill([
                'id' => $id,
                'partner_id' => $parent?->partner_id ?? $partner->getKey(),
                'parent_id' => $parent?->getKey(),
                'root_id' => $parent?->root_id ?? $id,
                'path' => $this->hierarchy->pathFor($id, $parent),
                'depth' => $depth,
                'type' => $type,
                'status' => $attributes['status'] ?? OrganizationStatus::Active,
            ])->save();

            $this->audit->record(
                action: 'organization.created',
                target: $organization,
                new: [
                    'type' => $type->value,
                    'parent_id' => $organization->parent_id,
                    'name' => $organization->name,
                ],
                actor: $actor,
                partnerId: $organization->partner_id,
            );

            return $organization;
        });
    }
}
