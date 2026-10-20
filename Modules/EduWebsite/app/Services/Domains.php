<?php

namespace Modules\EduWebsite\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Partners\Contracts\DnsTxtLookup;
use App\Platform\Partners\Models\PartnerDomain;
use App\Platform\Partners\Services\HostResolver;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Sites\SiteAddress;
use App\Platform\Sites\SiteResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\EduWebsite\Exceptions\WebsiteException;
use Modules\EduWebsite\Models\Site;

/**
 * A website's own domain ("www.abcschool.edu.bd"): offered when the rule
 * edu_website.own_domain allows it (per plan or partner); never the
 * platform's hosts, the sites domain, a partner's domain or another site's;
 * working only once a DNS TXT record proves it. Every change is audited and
 * starts the site's pages afresh (their canonical address changes).
 */
class Domains
{
    /** A DNS name of at least two labels, letters last (no addresses). */
    public const HOST_PATTERN = '/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/';

    public function __construct(
        private Sites $sites,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private SiteResolver $resolver,
        private HostResolver $hosts,
        private DnsTxtLookup $dns,
        private AuditLogger $audit,
    ) {}

    public function allowed(Organization $company): bool
    {
        return (bool) $this->rules->get('edu_website.own_domain', $this->contexts->forOrganization($company));
    }

    public function address(Organization $company): ?SiteAddress
    {
        return SiteAddress::query()->where('organization_id', $company->getKey())->where('module', Sites::MODULE)->first();
    }

    public function set(Organization $company, Site $site, string $host, User $actor): SiteAddress
    {
        if (! $this->allowed($company)) {
            throw WebsiteException::ownDomainOff();
        }
        $host = strtolower(trim($host, ". \t"));
        if (preg_match(self::HOST_PATTERN, $host) !== 1 || $this->reserved($host)) {
            throw WebsiteException::domainReserved();
        }

        [$address, $old] = DB::transaction(function () use ($company, $host, $actor) {
            $address = SiteAddress::query()->where('organization_id', $company->getKey())->where('module', Sites::MODULE)->lockForUpdate()->firstOrFail();
            $taken = SiteAddress::query()->where('domain', $host)->whereKeyNot($address->getKey())->exists()
                || PartnerDomain::query()->where('host', $host)->exists();
            if ($taken) {
                throw WebsiteException::domainTaken();
            }
            $old = $address->domain;
            if ($old !== $host) {
                $address->forceFill(['domain' => $host, 'domain_token' => Str::random(40), 'domain_verified_at' => null])->save();
                $this->audit->record('edu_website.domain_added', $address, old: ['domain' => $old], new: ['domain' => $host], actor: $actor, organizationId: $company->getKey());
            }

            return [$address, $old];
        });
        $this->changed($company, $site, $old, $host);

        return $address;
    }

    /** Proven when the TXT record at _onesolution-site.{domain} carries the token. */
    public function verify(Organization $company, Site $site, User $actor): SiteAddress
    {
        $address = $this->address($company);
        if ($address?->domain === null) {
            throw WebsiteException::noDomain();
        }
        if ($address->domain_verified_at !== null) {
            return $address;
        }
        if (! $this->allowed($company)) {
            throw WebsiteException::ownDomainOff();
        }
        $found = $this->dns->txt($address->txtName());
        if (! in_array($address->txtValue(), array_map('trim', $found), true)) {
            throw WebsiteException::domainNotProven($address->txtName(), $address->txtValue());
        }
        $address->forceFill(['domain_verified_at' => now()])->save();
        $this->audit->record('edu_website.domain_verified', $address, new: ['domain' => $address->domain], actor: $actor, organizationId: $company->getKey());
        $this->changed($company, $site, $address->domain, $address->domain);

        return $address;
    }

    public function remove(Organization $company, Site $site, User $actor): void
    {
        $address = $this->address($company);
        if ($address?->domain === null) {
            throw WebsiteException::noDomain();
        }
        $old = $address->domain;
        $address->forceFill(['domain' => null, 'domain_token' => null, 'domain_verified_at' => null])->save();
        $this->audit->record('edu_website.domain_removed', $address, old: ['domain' => $old], actor: $actor, organizationId: $company->getKey());
        $this->changed($company, $site, $old, null);
    }

    /** The app's own hosts, the sites domain and its names, and local names are never a site's domain. */
    private function reserved(string $host): bool
    {
        $sites = strtolower((string) config('sites.domain'));

        return $this->hosts->isPlatformHost($host)
            || $host === $sites || str_ends_with($host, '.'.$sites)
            || $host === strtolower((string) config('sites.cname_target'))
            || in_array(last(explode('.', $host)), ['localhost', 'local', 'internal', 'test', 'invalid', 'example'], true);
    }

    private function changed(Organization $company, Site $site, ?string $old, ?string $new): void
    {
        $this->resolver->forgetDomain($old);
        $this->resolver->forgetDomain($new);
        $this->sites->touch($company, $site->getKey());
    }
}
