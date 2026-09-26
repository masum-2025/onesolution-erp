<?php

namespace App\Platform\Partners;

use App\Platform\Partners\Models\PartnerDomain;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;

/**
 * Which address the request came to. On a platform host (the house domain)
 * every account may work; on a partner's verified domain only that partner's
 * contexts, and on a client's own domain only that client's organizations.
 * Filled by ResolveHost; scoped per request (jobs run as platform).
 */
final class HostContext
{
    private ?Partner $partner = null;

    private ?string $clientRootId = null;

    public function forPlatform(): void
    {
        $this->partner = null;
        $this->clientRootId = null;
    }

    public function forDomain(PartnerDomain $domain): void
    {
        $this->partner = $domain->partner;
        $this->clientRootId = $domain->organization_id === null ? null : $domain->organization?->root_id;
    }

    public function isPlatform(): bool
    {
        return $this->partner === null;
    }

    public function partner(): ?Partner
    {
        return $this->partner;
    }

    public function clientRootId(): ?string
    {
        return $this->clientRootId;
    }

    public function allowsPartner(string $partnerId): bool
    {
        // A client's own domain is for that client, not for the partner console.
        return $this->isPlatform() || ($this->partner->getKey() === $partnerId && $this->clientRootId === null);
    }

    public function allowsOrganization(Organization $organization): bool
    {
        if ($this->isPlatform()) {
            return true;
        }

        return $organization->partner_id === $this->partner->getKey()
            && ($this->clientRootId === null || $organization->root_id === $this->clientRootId);
    }
}
