<?php

namespace App\Platform\Partners\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Packaging\Actions\ApplySectorPackage;
use App\Platform\Packaging\Services\UsageLimiter;
use App\Platform\Partners\Events\CompanyAddedToGroup;
use App\Platform\Partners\Exceptions\PartnerException;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\Enums\RuleValueStatus;
use App\Platform\Rules\Models\RuleValue;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Rules\RuleTargets;
use App\Platform\Rules\Services\RuleService;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Actions\CreateOrganization;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\OrganizationStatus;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Facades\DB;

/**
 * Client accounts from the partner console: create (a single company, or a
 * group with its first company, or another company in a group already
 * served; with its sector package, optional first branches and owner),
 * suspend / reactivate, per-client limit deals. Only the partner's own top
 * organizations; never business data.
 */
class PartnerClientService
{
    public const STANDALONE = 'company';

    public const GROUP = 'group';

    public const EXISTING_GROUP = 'existing_group';

    public function __construct(
        private CreateOrganization $create,
        private AddMember $addMember,
        private ApplySectorPackage $package,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private RuleService $ruleService,
        private RuleTargets $targets,
        private AuditLogger $audit,
    ) {}

    /**
     * A new company for a client, in one of three shapes (the partner picks):
     *
     * - company: a single company at the top, no group (the default);
     * - group: a new group with its first company;
     * - existing_group: another company in a group the partner already serves
     *   (same account, plan and billing: no new client slot).
     *
     * Optional first branches are created under the company. The company gets
     * its sector package. The owner is made owner of the new top organization
     * (or of the new company, in an existing group).
     *
     * @param  array{structure?: string, name: array<string, string>, group_name?: array<string, string>|null, group_id?: string|null, branches?: list<array<string, string>>, sector_key: string, plan?: string|null, country_code?: string|null, currency_code?: string|null, timezone?: string|null, default_locale?: string|null}  $data
     * @return array{client: Organization, company: Organization, branches: list<Organization>}
     */
    public function create(Partner $partner, array $data, ?User $owner, User $actor): array
    {
        $structure = $data['structure'] ?? self::STANDALONE;
        $this->assertCountryAllowed($partner, $data['country_code'] ?? null);

        return DB::transaction(function () use ($partner, $data, $owner, $actor, $structure) {
            // Lock the partner row: two new clients at once cannot pass the limit together.
            Partner::query()->whereKey($partner->getKey())->lockForUpdate()->first();

            $regional = array_filter([
                'country_code' => $data['country_code'] ?? null,
                'currency_code' => $data['currency_code'] ?? null,
                'timezone' => $data['timezone'] ?? null,
                'default_locale' => $data['default_locale'] ?? null,
            ], fn ($value) => $value !== null);
            $companyAttributes = ['name' => $data['name'], 'sector_key' => $data['sector_key']];

            if ($structure === self::EXISTING_GROUP) {
                $client = $this->servedGroup($partner, (string) $data['group_id']);
                $company = $this->create->handle(OrganizationType::Company, [...$companyAttributes, ...$regional], parent: $client, actor: $actor);
                $ownerAt = $company;
            } elseif ($structure === self::GROUP) {
                $this->assertClientSlot($partner);
                $client = $this->create->handle(OrganizationType::Group, ['name' => $data['group_name'] ?? $data['name'], ...$regional], partner: $partner, actor: $actor);
                $client->forceFill(['plan_key' => $data['plan']])->save();
                $company = $this->create->handle(OrganizationType::Company, $companyAttributes, parent: $client, actor: $actor);
                $ownerAt = $client;
            } else {
                $this->assertClientSlot($partner);
                $client = $company = $this->create->handle(OrganizationType::Company, [...$companyAttributes, ...$regional], partner: $partner, actor: $actor);
                $client->forceFill(['plan_key' => $data['plan']])->save();
                $ownerAt = $client;
            }

            $branches = [];
            foreach ($data['branches'] ?? [] as $name) {
                $branches[] = $this->create->handle(OrganizationType::Branch, ['name' => $name], parent: $company->fresh(), actor: $actor);
            }

            // The owner manages everything below where they are made owner.
            if ($owner !== null) {
                $this->addMember->handle($ownerAt, $owner, MembershipType::Owner, AccessScope::Descendants, $actor);
            }
            $this->package->handle($company->fresh(), $actor);

            $this->audit->record(
                action: $structure === self::EXISTING_GROUP ? 'partner.company_added' : 'partner.client_created',
                target: $company,
                new: [
                    'structure' => $structure,
                    'name' => $data['name'],
                    'plan' => $structure === self::EXISTING_GROUP ? null : $data['plan'],
                    'sector' => $data['sector_key'],
                    'branches' => count($branches),
                    'owner' => $owner?->getKey(),
                ],
                actor: $actor,
                organizationId: $client->getKey(),
                partnerId: $partner->getKey(),
            );

            if ($structure === self::EXISTING_GROUP) {
                CompanyAddedToGroup::dispatch($client, $company, $partner);
            }

            return ['client' => $client->fresh(), 'company' => $company->fresh(), 'branches' => array_map(fn (Organization $branch) => $branch->fresh(), $branches)];
        });
    }

    /**
     * A group at the top that this partner serves and that can take a new
     * company now. Anything else (another partner's, a single company, a
     * suspended client) is refused.
     */
    private function servedGroup(Partner $partner, string $id): Organization
    {
        $group = Organization::query()
            ->where('partner_id', $partner->getKey())
            ->whereNull('parent_id')
            ->where('type', OrganizationType::Group)
            ->whereKey($id)
            ->lockForUpdate()
            ->first();

        if ($group === null) {
            throw PartnerException::clientNotFound();
        }
        if ($group->status !== OrganizationStatus::Active) {
            throw PartnerException::groupNotActive();
        }

        return $group;
    }

    public function setStatus(Organization $client, OrganizationStatus $status, string $reason, User $actor): Organization
    {
        if ($client->status === $status) {
            return $client;
        }

        return DB::transaction(function () use ($client, $status, $reason, $actor) {
            $old = $client->status->value;
            $client->forceFill(['status' => $status])->save();

            $this->audit->record(
                action: 'partner.client_status_changed',
                target: $client,
                old: ['status' => $old],
                new: ['status' => $status->value],
                reason: $reason,
                actor: $actor,
                organizationId: $client->getKey(),
                partnerId: $client->partner_id,
            );

            return $client;
        });
    }

    /**
     * A per-client deal: limit values at the client's top organization. Null = follow the plan.
     *
     * @param  array<string, int|null>  $limits  users / branches / storage_mb
     */
    public function setLimits(Organization $client, array $limits, string $reason, User $actor): void
    {
        DB::transaction(function () use ($client, $limits, $reason, $actor) {
            $target = $this->targets->organization($client);

            foreach ($limits as $limit => $value) {
                $rule = UsageLimiter::LIMITS[$limit];

                if ($value !== null) {
                    // The partner writes the client's deal: no maker-checker inside the client.
                    $this->ruleService->set($target, $rule, RuleMode::Set, $value, $reason, $actor, trusted: true);

                    continue;
                }

                $own = RuleValue::query()
                    ->where('rule_key', $rule)
                    ->where('scope_type', $target->scope)
                    ->where('scope_id', $target->scopeId)
                    ->where('status', RuleValueStatus::Active)
                    ->exists();

                if ($own) {
                    $this->ruleService->reset($target, $rule, $reason, $actor);
                }
            }
        });
    }

    /**
     * @return array<string, int|null> The client's own deal per limit (null = follows the plan).
     */
    public function dealOf(Organization $client): array
    {
        $target = $this->targets->organization($client);
        $own = RuleValue::query()
            ->whereIn('rule_key', array_values(UsageLimiter::LIMITS))
            ->where('scope_type', $target->scope)
            ->where('scope_id', $target->scopeId)
            ->where('status', RuleValueStatus::Active)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->get()
            ->keyBy('rule_key');

        return array_map(fn (string $rule) => $own->get($rule)?->value, UsageLimiter::LIMITS);
    }

    /** The platform's cap on a partner's clients (also checked when a client moves in). */
    public function assertClientSlot(Partner $partner): void
    {
        $max = $this->rules->get('partners.max_clients', $this->contexts->forPartner($partner));

        if ($max === null) {
            return;
        }

        $clients = Organization::query()
            ->where('partner_id', $partner->getKey())
            ->whereNull('parent_id')
            // Self-serve personal workspaces are not business clients: they do not use a slot.
            ->where('type', '!=', OrganizationType::Personal)
            ->where('status', '!=', OrganizationStatus::Archived)
            ->count();

        if ($clients >= $max) {
            throw PartnerException::clientLimitReached((int) $max);
        }
    }

    public function assertCountryAllowed(Partner $partner, ?string $country): void
    {
        $allowed = $this->rules->get('partners.allowed_countries', $this->contexts->forPartner($partner));

        if (is_array($allowed) && ($country === null || ! in_array($country, $allowed, true))) {
            throw PartnerException::countryNotAllowed($country ?? '—');
        }
    }
}
