<?php

namespace App\Platform\Tenancy\Context;

use App\Models\User;
use App\Platform\Tenancy\Exceptions\MissingTenantContext;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use App\Platform\Tenancy\Models\Partner;
use App\Platform\Tenancy\Models\PartnerUser;
use Illuminate\Support\Collection;

/**
 * Who is acting, and in which tenant, for the current request or job.
 *
 * Filled only by ContextResolver from verified server-side data (the API
 * token and the membership rows). Bound as a scoped singleton, so it is
 * reset between requests and queued jobs.
 */
final class CurrentContext
{
    private ?User $user = null;

    private ?Partner $partner = null;

    private ?PartnerUser $partnerUser = null;

    private ?Organization $organization = null;

    private ?OrganizationMembership $membership = null;

    private ?Organization $company = null;

    private ?Organization $group = null;

    /** @var Collection<int, Organization> Root first, parent last. */
    private Collection $ancestors;

    /** Path prefix of the subtree this context may read. */
    private ?string $visiblePath = null;

    private bool $includeDescendants = true;

    /** Path prefix of the subtree this context may write to. */
    private ?string $writablePath = null;

    private bool $writeIncludesDescendants = true;

    /** @var array<string, string|null> */
    private array $settings = [];

    /** @var array<string, bool> */
    private array $writableCache = [];

    public function __construct()
    {
        $this->ancestors = new Collection;
    }

    /**
     * @param  Collection<int, Organization>  $ancestors  Root first.
     * @param  array<string, string|null>  $settings  Effective country/locale/timezone/currency/region.
     */
    public function enterOrganization(
        User $user,
        OrganizationMembership $membership,
        Collection $ancestors,
        ?Organization $company,
        ?Organization $group,
        string $visiblePath,
        bool $includeDescendants,
        string $writablePath,
        bool $writeIncludesDescendants,
        array $settings,
    ): void {
        $this->clear();

        $this->user = $user;
        $this->membership = $membership;
        $this->organization = $membership->organization;
        $this->partner = $membership->organization->partner;
        $this->ancestors = $ancestors;
        $this->company = $company;
        $this->group = $group;
        $this->visiblePath = $visiblePath;
        $this->includeDescendants = $includeDescendants;
        $this->writablePath = $writablePath;
        $this->writeIncludesDescendants = $writeIncludesDescendants;
        $this->settings = $settings;
    }

    public function enterPartner(User $user, PartnerUser $partnerUser): void
    {
        $this->clear();

        $this->user = $user;
        $this->partnerUser = $partnerUser;
        $this->partner = $partnerUser->partner;
    }

    public function clear(): void
    {
        $this->user = null;
        $this->partner = null;
        $this->partnerUser = null;
        $this->organization = null;
        $this->membership = null;
        $this->company = null;
        $this->group = null;
        $this->ancestors = new Collection;
        $this->visiblePath = null;
        $this->includeDescendants = true;
        $this->writablePath = null;
        $this->writeIncludesDescendants = true;
        $this->settings = [];
        $this->writableCache = [];
    }

    public function hasOrganization(): bool
    {
        return $this->organization !== null;
    }

    public function hasPartnerConsole(): bool
    {
        return $this->partnerUser !== null;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function partner(): Partner
    {
        return $this->partner ?? throw new MissingTenantContext;
    }

    public function partnerUser(): PartnerUser
    {
        return $this->partnerUser ?? throw new MissingTenantContext;
    }

    public function organization(): Organization
    {
        return $this->organization ?? throw new MissingTenantContext;
    }

    public function membership(): OrganizationMembership
    {
        return $this->membership ?? throw new MissingTenantContext;
    }

    public function company(): ?Organization
    {
        return $this->company;
    }

    public function group(): ?Organization
    {
        return $this->group;
    }

    /**
     * @return Collection<int, Organization>
     */
    public function ancestors(): Collection
    {
        return $this->ancestors;
    }

    public function visiblePath(): string
    {
        return $this->visiblePath ?? throw new MissingTenantContext;
    }

    public function includesDescendants(): bool
    {
        return $this->includeDescendants;
    }

    public function locale(): ?string
    {
        return $this->settings['default_locale'] ?? null;
    }

    public function country(): ?string
    {
        return $this->settings['country_code'] ?? null;
    }

    public function region(): ?string
    {
        return $this->settings['region'] ?? null;
    }

    public function timezone(): ?string
    {
        return $this->settings['timezone'] ?? null;
    }

    public function currency(): ?string
    {
        return $this->settings['currency_code'] ?? null;
    }

    /**
     * Whether tenant-scoped records of this organization may be created,
     * changed or deleted from the current context.
     */
    public function canWriteTo(?string $organizationId): bool
    {
        if (! $this->hasOrganization() || $organizationId === null || $this->writablePath === null) {
            return false;
        }

        return $this->writableCache[$organizationId] ??= Organization::query()
            ->whereKey($organizationId)
            ->where('partner_id', $this->partner()->getKey())
            ->when(
                $this->writeIncludesDescendants,
                fn ($query) => $query->where('path', 'like', $this->writablePath.'%'),
                fn ($query) => $query->where('path', $this->writablePath),
            )
            ->exists();
    }
}
