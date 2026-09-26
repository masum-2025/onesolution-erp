<?php

namespace App\Platform\Partners\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Packaging\Actions\ApplySectorPackage;
use App\Platform\Packaging\Services\UsageLimiter;
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
 * Client accounts from the partner console: create (group + first company
 * with its sector package + owner), suspend / reactivate, per-client limit
 * deals. Only the partner's own top organizations; never business data.
 */
class PartnerClientService
{
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
     * @param  array{name: array<string, string>, sector_key: string, plan: string, country_code?: string|null, currency_code?: string|null, timezone?: string|null, default_locale?: string|null}  $data
     * @return array{group: Organization, company: Organization}
     */
    public function create(Partner $partner, array $data, User $owner, User $actor): array
    {
        $this->assertCountryAllowed($partner, $data['country_code'] ?? null);

        return DB::transaction(function () use ($partner, $data, $owner, $actor) {
            // Lock the partner row: two new clients at once cannot pass the limit together.
            Partner::query()->whereKey($partner->getKey())->lockForUpdate()->first();
            $this->assertClientSlot($partner);

            $regional = array_filter([
                'country_code' => $data['country_code'] ?? null,
                'currency_code' => $data['currency_code'] ?? null,
                'timezone' => $data['timezone'] ?? null,
                'default_locale' => $data['default_locale'] ?? null,
            ], fn ($value) => $value !== null);

            $group = $this->create->handle(OrganizationType::Group, ['name' => $data['name'], ...$regional], partner: $partner, actor: $actor);
            $group->forceFill(['plan_key' => $data['plan']])->save();

            $company = $this->create->handle(OrganizationType::Company, ['name' => $data['name'], 'sector_key' => $data['sector_key']], parent: $group, actor: $actor);

            // The client's owner manages the whole account (the group and every company in it).
            $this->addMember->handle($group, $owner, MembershipType::Owner, AccessScope::Descendants, $actor);
            $this->package->handle($company->fresh(), $actor);

            $this->audit->record(
                action: 'partner.client_created',
                target: $group,
                new: ['name' => $data['name'], 'plan' => $data['plan'], 'sector' => $data['sector_key'], 'owner' => $owner->getKey()],
                actor: $actor,
                organizationId: $group->getKey(),
                partnerId: $partner->getKey(),
            );

            return ['group' => $group->fresh(), 'company' => $company->fresh()];
        });
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

    private function assertClientSlot(Partner $partner): void
    {
        $max = $this->rules->get('partners.max_clients', $this->contexts->forPartner($partner));

        if ($max === null) {
            return;
        }

        $clients = Organization::query()
            ->where('partner_id', $partner->getKey())
            ->whereNull('parent_id')
            ->where('status', '!=', OrganizationStatus::Archived)
            ->count();

        if ($clients >= $max) {
            throw PartnerException::clientLimitReached((int) $max);
        }
    }

    private function assertCountryAllowed(Partner $partner, ?string $country): void
    {
        $allowed = $this->rules->get('partners.allowed_countries', $this->contexts->forPartner($partner));

        if (is_array($allowed) && ($country === null || ! in_array($country, $allowed, true))) {
            throw PartnerException::countryNotAllowed($country ?? '—');
        }
    }
}
