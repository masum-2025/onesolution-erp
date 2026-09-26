<?php

namespace App\Platform\Partners\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Partners\Contracts\DnsTxtLookup;
use App\Platform\Partners\Enums\DomainStatus;
use App\Platform\Partners\Exceptions\PartnerException;
use App\Platform\Partners\Models\PartnerDomain;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Custom domains: added as pending with a random token, activated only after
 * the TXT record "_onesolution-verify.{host}" shows that token. Only active
 * domains resolve (ResolveHost) or get a certificate (TlsAskController).
 */
class DomainService
{
    public function __construct(
        private DnsTxtLookup $dns,
        private HostResolver $hosts,
        private AuditLogger $audit,
    ) {}

    public function add(Partner $partner, string $host, ?Organization $client, User $actor): PartnerDomain
    {
        $host = strtolower(trim($host));

        if ($this->hosts->isPlatformHost($host)) {
            throw PartnerException::hostReserved();
        }

        if ($client !== null && ($client->partner_id !== $partner->getKey() || ! $client->isRoot())) {
            throw PartnerException::clientNotFound();
        }

        return DB::transaction(function () use ($partner, $host, $client, $actor) {
            if (PartnerDomain::query()->where('host', $host)->lockForUpdate()->exists()) {
                throw PartnerException::hostTaken();
            }

            $domain = PartnerDomain::create([
                'partner_id' => $partner->getKey(),
                'organization_id' => $client?->getKey(),
                'host' => $host,
                'status' => DomainStatus::Pending,
                'verification_token' => Str::random(40),
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->record(
                action: 'partner.domain_added',
                target: $domain,
                new: ['host' => $host, 'client' => $client?->getKey()],
                actor: $actor,
                organizationId: $client?->getKey(),
                partnerId: $partner->getKey(),
            );

            return $domain;
        });
    }

    /**
     * Check the TXT record now; activate the domain when it matches.
     */
    public function verify(PartnerDomain $domain, User $actor): PartnerDomain
    {
        $found = $this->dns->txt($domain->txtName());
        $matches = in_array($domain->txtValue(), array_map('trim', $found), true);

        $domain->forceFill(['last_checked_at' => now()])->save();

        if (! $matches) {
            throw PartnerException::verificationFailed($domain->txtName(), $domain->txtValue(), array_slice($found, 0, 5));
        }

        if ($domain->isActive()) {
            return $domain;
        }

        return DB::transaction(function () use ($domain, $actor) {
            $domain->forceFill(['status' => DomainStatus::Active, 'verified_at' => now()])->save();
            $this->hosts->forget($domain->host);

            $this->audit->record(
                action: 'partner.domain_verified',
                target: $domain,
                new: ['host' => $domain->host],
                actor: $actor,
                organizationId: $domain->organization_id,
                partnerId: $domain->partner_id,
            );

            return $domain;
        });
    }

    public function remove(PartnerDomain $domain, User $actor, string $reason): void
    {
        DB::transaction(function () use ($domain, $actor, $reason) {
            $this->audit->record(
                action: 'partner.domain_removed',
                target: $domain,
                old: ['host' => $domain->host, 'status' => $domain->status->value],
                reason: $reason,
                actor: $actor,
                organizationId: $domain->organization_id,
                partnerId: $domain->partner_id,
            );

            $domain->delete();
            $this->hosts->forget($domain->host);
        });
    }
}
