<?php

namespace App\Platform\Partners\Http\Controllers\Concerns;

use App\Platform\Partners\Exceptions\PartnerException;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;

/**
 * Partner console helpers: the partner comes from the context only, roles
 * are checked per action, and a client is always one of this partner's own
 * top organizations (anything else is the same 404).
 */
trait PartnerConsole
{
    protected function partner(): Partner
    {
        return app(CurrentContext::class)->partner();
    }

    protected function hasRole(PartnerUserRole ...$roles): bool
    {
        return in_array(app(CurrentContext::class)->partnerUser()->role, $roles, true);
    }

    protected function requireRole(PartnerUserRole ...$roles): void
    {
        if (! $this->hasRole(...$roles)) {
            throw PartnerException::roleNotAllowed();
        }
    }

    protected function client(string $id): Organization
    {
        return Organization::query()
            ->where('partner_id', $this->partner()->getKey())
            ->whereNull('parent_id')
            ->whereKey($id)
            ->first() ?? throw PartnerException::clientNotFound();
    }
}
