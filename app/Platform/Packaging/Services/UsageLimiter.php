<?php

namespace App\Platform\Packaging\Services;

use App\Models\User;
use App\Platform\Packaging\Exceptions\PackagingException;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\OrganizationStatus;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;

/**
 * Usage limits of a subscription: the whole tree under its top organization.
 * Limits are rules (plans.max_*), resolved for the top organization: plan
 * values, else partner or platform defaults. Organizations cannot set them.
 *
 * Checks lock the top organization's row, so two requests at once cannot
 * both take the last seat. Callers run them inside their write transaction.
 */
class UsageLimiter
{
    public const LIMITS = ['users' => 'plans.max_users', 'branches' => 'plans.max_branches', 'storage_mb' => 'plans.max_storage_mb'];

    /** Membership types and statuses that take a seat. Portal users never do. */
    private const SEAT_TYPES = [MembershipType::Owner, MembershipType::Staff];

    private const SEAT_STATUSES = [MembershipStatus::Active, MembershipStatus::Invited];

    public function __construct(
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private PlanCatalog $plans,
    ) {}

    /**
     * @return array<string, int|null> Limit per kind; null = unlimited.
     */
    public function limits(Organization $organization): array
    {
        $context = $this->contexts->forOrganization($this->root($organization));

        return array_map(fn (string $rule) => $this->rules->get($rule, $context), self::LIMITS);
    }

    /**
     * @return array{users: int, branches: int, storage_mb: int|null}
     */
    public function usage(Organization $organization): array
    {
        $root = $this->root($organization);

        return [
            'users' => $this->seatQuery($root)->distinct()->count('organization_user.user_id'),
            'branches' => $this->branchCount($root),
            // Measured once files arrive; nothing is stored yet.
            'storage_mb' => null,
        ];
    }

    /**
     * Before a membership starts taking a seat (new owner/staff member, a
     * suspended one reactivated, a portal user made staff).
     */
    public function assertSeatAvailable(Organization $organization, User $user, MembershipType $type, ?string $ignoreMembershipId = null): void
    {
        if (! in_array($type, self::SEAT_TYPES, true)) {
            return;
        }

        $root = $this->lockRoot($organization);
        $seats = $this->seatQuery($root)->when($ignoreMembershipId, fn ($query) => $query->where('organization_user.id', '!=', $ignoreMembershipId));

        // One person counts once, however many units they belong to.
        if ((clone $seats)->where('organization_user.user_id', $user->getKey())->exists()) {
            return;
        }

        $this->assertBelow('users', $root, $seats->distinct()->count('organization_user.user_id'));
    }

    public function assertBranchAvailable(Organization $parent): void
    {
        $root = $this->lockRoot($parent);

        $this->assertBelow('branches', $root, $this->branchCount($root));
    }

    /**
     * Public plans that would allow more of a limit than the current one.
     *
     * @return list<array{key: string, name: string, max: int|null}>
     */
    public function upgradesFor(string $limit, int $needed, ?string $currentPlan): array
    {
        $options = [];

        foreach ($this->plans->all() as $plan) {
            if (! $plan->public || $plan->key === $currentPlan) {
                continue;
            }

            $max = $this->rules->get(self::LIMITS[$limit], $this->contexts->forPlan($plan->key));

            if ($max === null || $max >= $needed) {
                $options[] = ['key' => $plan->key, 'name' => $plan->label(), 'max' => $max];
            }
        }

        return $options;
    }

    public function root(Organization $organization): Organization
    {
        return $organization->isRoot() ? $organization : Organization::query()->findOrFail($organization->root_id);
    }

    private function assertBelow(string $limit, Organization $root, int $used): void
    {
        $max = $this->limits($root)[$limit];

        if ($max === null || $used < $max) {
            return;
        }

        $plan = $this->planOf($root);

        throw PackagingException::limitReached(
            $limit,
            $max,
            $used,
            $this->plans->has($plan) ? $this->plans->get($plan)->label() : (string) $plan,
            $this->upgradesFor($limit, $used + 1, $plan),
        );
    }

    private function lockRoot(Organization $organization): Organization
    {
        return Organization::query()->whereKey($organization->root_id)->lockForUpdate()->firstOrFail();
    }

    private function planOf(Organization $root): ?string
    {
        return $root->plan_key ?? config('tenancy.defaults.plan_key');
    }

    private function seatQuery(Organization $root)
    {
        return OrganizationMembership::query()
            ->whereIn('organization_user.organization_id', Organization::query()->where('root_id', $root->getKey())->select('id'))
            ->whereIn('organization_user.membership_type', self::SEAT_TYPES)
            ->whereIn('organization_user.status', self::SEAT_STATUSES);
    }

    private function branchCount(Organization $root): int
    {
        return Organization::query()
            ->where('root_id', $root->getKey())
            ->where('type', OrganizationType::Branch)
            ->where('status', '!=', OrganizationStatus::Archived)
            ->count();
    }
}
