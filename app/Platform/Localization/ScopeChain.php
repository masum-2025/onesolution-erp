<?php

namespace App\Platform\Localization;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Partners\HostContext;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Collection;

/**
 * The levels whose wording someone reads, most general first:
 *
 *   platform -> partner -> group -> company
 *
 * A group's and a company's own wording apply only while the multi_language
 * module is on there and the partner allows clients their own wording
 * (rule i18n.allow_overrides). Turned off, their texts stay stored, unused.
 */
class ScopeChain
{
    public const MODULE = 'multi_language';

    public function __construct(
        private CurrentContext $context,
        private HostContext $host,
        private ModuleResolver $modules,
        private RuleResolver $rules,
        private RuleContextFactory $ruleContexts,
    ) {}

    /**
     * The chain of the current request: the organization worked in, else
     * the partner console, else the address (a partner's or client's domain).
     *
     * @return list<TranslationScope>
     */
    public function current(): array
    {
        if ($this->context->hasOrganization()) {
            // The context already holds the ancestors: no query for them.
            return $this->forOrganization($this->context->organization(), $this->context->ancestors());
        }

        if ($this->context->hasPartnerConsole()) {
            return $this->forPartner($this->context->partner());
        }

        $client = $this->host->client();
        if ($client !== null) {
            return $this->forOrganization($client);
        }

        $partner = $this->host->partner();

        return $partner === null ? [TranslationScope::platform()] : $this->forPartner($partner);
    }

    /** @return list<TranslationScope> */
    public function forPartner(Partner $partner): array
    {
        return [TranslationScope::platform(), TranslationScope::partner($partner)];
    }

    /**
     * @param  Collection<int, Organization>|null  $ancestors  Root first; loaded when null.
     * @return list<TranslationScope>
     */
    public function forOrganization(Organization $organization, ?Collection $ancestors = null): array
    {
        $partner = $organization->relationLoaded('partner') ? $organization->partner : $organization->partner()->first();
        $chain = $partner === null ? [TranslationScope::platform()] : $this->forPartner($partner);

        if (! $this->ownWordingAllowed($organization, $ancestors)) {
            return $chain;
        }

        foreach ($this->wordingLevels($organization, $ancestors) as $level) {
            $chain[] = TranslationScope::organization($level);
        }

        return $chain;
    }

    /** Whether a group or company may have its own wording at all. */
    public function ownWordingAllowed(Organization $organization, ?Collection $ancestors = null): bool
    {
        return $this->modules->isEnabled(self::MODULE, $organization)
            && (bool) $this->rules->get('i18n.allow_overrides', $this->ruleContexts->forOrganization($organization, ancestors: $ancestors?->values()));
    }

    /** Organizations that can hold wording: a group, or a company (or personal workspace). */
    public static function canHoldWording(Organization $organization): bool
    {
        return $organization->type === OrganizationType::Group || $organization->type->isCompanyLike();
    }

    /**
     * The group at the top (if the top is a group) and the nearest company
     * at or above the organization.
     *
     * @param  Collection<int, Organization>|null  $ancestors
     * @return list<Organization>
     */
    private function wordingLevels(Organization $organization, ?Collection $ancestors): array
    {
        $ancestors ??= Organization::query()->whereKey($organization->ancestorIds())->orderBy('depth')->get();
        $chain = $ancestors->concat([$organization])->values();

        $levels = [];
        $root = $chain->first();
        if ($root->type === OrganizationType::Group) {
            $levels[] = $root;
        }

        $company = $chain->reverse()->first(fn (Organization $node) => $node->type->isCompanyLike());
        if ($company !== null) {
            $levels[] = $company;
        }

        return $levels;
    }
}
