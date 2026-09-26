<?php

namespace App\Platform\Notifications\Services;

use App\Platform\Partners\Enums\DomainStatus;
use App\Platform\Partners\Models\PartnerDomain;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;

/**
 * Links in messages open the partner's own address (a client's own domain
 * first, then the partner's), so a white-label client never sees ours.
 * Without a verified domain, the platform address.
 */
class LinkBuilder
{
    public function base(?Partner $partner, ?Organization $organization = null): string
    {
        if ($partner === null || $partner->is_house) {
            return rtrim((string) config('app.url'), '/');
        }

        $domains = PartnerDomain::query()
            ->where('partner_id', $partner->getKey())
            ->where('status', DomainStatus::Active)
            ->orderBy('created_at')
            ->get();

        $root = $organization === null ? null : ($organization->isRoot() ? $organization->getKey() : $organization->root_id);
        $domain = $domains->firstWhere('organization_id', $root) ?? $domains->firstWhere('organization_id', null);

        if ($domain === null) {
            return rtrim((string) config('app.url'), '/');
        }

        // Local development hosts (*.localhost) have no certificate.
        $scheme = str_ends_with($domain->host, '.localhost') ? 'http' : 'https';

        return "{$scheme}://{$domain->host}";
    }

    public function to(string $path, ?Partner $partner, ?Organization $organization = null): string
    {
        return $this->base($partner, $organization).'/'.ltrim($path, '/');
    }

    /** An app asset path (/brand-assets/...) made absolute, as mail clients need. */
    public function absolute(?string $path, ?Partner $partner): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return str_starts_with($path, 'http') ? $path : $this->base($partner).'/'.ltrim($path, '/');
    }
}
