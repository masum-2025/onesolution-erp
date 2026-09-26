<?php

namespace App\Platform\Identity\Services;

use App\Models\User;
use App\Platform\Identity\Events\PersonalWorkspaceCreated;
use App\Platform\Identity\Exceptions\IdentityException;
use App\Platform\Packaging\PlanCatalog;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Actions\CreateOrganization;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A self-serve person's own workspace: an organization of type personal at
 * the top of the partner's tree, with the person as its only owner, on the
 * partner's plan for new individuals (rule b2c.default_plan).
 */
class PersonalWorkspaces
{
    public function __construct(
        private CreateOrganization $create,
        private AddMember $addMember,
        private PlanCatalog $plans,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function create(User $user, Partner $partner, ?string $countryCode, string $locale): Organization
    {
        $plan = (string) $this->rules->get('b2c.default_plan', $this->contexts->forPartner($partner));
        if (! $this->plans->has($plan) || $this->plans->get($plan)->audience !== PlanCatalog::PERSONAL) {
            throw new LogicException("Rule b2c.default_plan must name a personal plan, [{$plan}] is not one.");
        }

        return DB::transaction(function () use ($user, $partner, $countryCode, $locale, $plan) {
            $names = [];
            foreach ((array) config('tenancy.supported_locales') as $language) {
                $names[$language] = __('identity.workspace_name', ['name' => $user->name], $language);
            }

            $workspace = $this->create->handle(OrganizationType::Personal, array_filter([
                'name' => $names,
                'country_code' => $countryCode,
                'default_locale' => $locale,
            ], fn ($value) => $value !== null), partner: $partner, actor: $user);
            $workspace->forceFill(['plan_key' => $plan])->save();

            $this->addMember->handle($workspace, $user, MembershipType::Owner, AccessScope::Descendants, $user);

            PersonalWorkspaceCreated::dispatch($workspace->fresh(), $user);

            return $workspace->fresh();
        });
    }

    /**
     * The personal workspace the person owns (one per person and partner).
     */
    public function of(User $user, ?Partner $partner = null): Organization
    {
        return Organization::query()
            ->where('type', OrganizationType::Personal)
            ->when($partner !== null, fn ($query) => $query->where('partner_id', $partner->getKey()))
            ->whereIn('id', $user->memberships()->where('membership_type', MembershipType::Owner)->select('organization_id'))
            ->orderBy('created_at')
            ->first() ?? throw IdentityException::noPersonalWorkspace();
    }
}
