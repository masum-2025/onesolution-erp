<?php

namespace App\Platform\Rules;

use App\Platform\Rules\Enums\RuleScope;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use LogicException;

class RuleTargets
{
    public function __construct(private RuleContextFactory $contexts) {}

    public function platform(): RuleTarget
    {
        return new RuleTarget(RuleScope::Platform, null, $this->contexts->platform());
    }

    public function plan(string $plan): RuleTarget
    {
        return new RuleTarget(RuleScope::Plan, $plan, $this->contexts->forPlan($plan));
    }

    public function partner(Partner $partner): RuleTarget
    {
        return new RuleTarget(RuleScope::Partner, $partner->getKey(), $this->contexts->forPartner($partner), partner: $partner);
    }

    public function organization(Organization $organization): RuleTarget
    {
        return new RuleTarget(
            RuleScope::forOrganizationType($organization->type),
            $organization->getKey(),
            $this->contexts->forOrganization($organization),
            organization: $organization,
        );
    }

    /**
     * Rebuild the target of a stored value (for approvals and rollbacks).
     */
    public function fromStored(RuleScope $scope, ?string $scopeId): RuleTarget
    {
        return match (true) {
            $scope === RuleScope::Platform => $this->platform(),
            $scope === RuleScope::Plan => $this->plan((string) $scopeId),
            $scope === RuleScope::Partner => $this->partner(Partner::query()->findOrFail($scopeId)),
            $scope->isOrganizationLevel() => $this->organization(Organization::query()->findOrFail($scopeId)),
            // Role and user values are written by Phase 4 tooling.
            default => throw new LogicException("Rule values at {$scope->value} level are not managed here yet."),
        };
    }
}
