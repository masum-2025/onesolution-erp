<?php

namespace Modules\Zkteco\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Support\Str;
use Modules\Zkteco\Models\Bridge;
use Modules\Zkteco\Models\Device;

/**
 * Office bridges: made with a token shown once ("zkb.<company>.<secret>";
 * the company tells which client database to look in, only the SHA-256 of
 * the whole token is kept), revoked at any time (its devices go back to
 * being read by the app). Both are audited. A token names its bridge only
 * while it is not revoked.
 */
class Bridges
{
    /** Characters of the secret part. */
    private const SECRET_LENGTH = 48;

    public function __construct(private Place $place, private AuditLogger $audit) {}

    /** @return array{0: Bridge, 1: string} The bridge and its token (shown once). */
    public function create(Organization $company, string $unitId, string $name, User $actor): array
    {
        $token = 'zkb.'.$company->getKey().'.'.Str::random(self::SECRET_LENGTH);
        $bridge = new Bridge([
            'organization_id' => $company->getKey(), 'unit_id' => $unitId, 'name' => $name,
            'token_hash' => hash('sha256', $token), 'token_hint' => substr($token, -4), 'created_by' => $actor->getKey(),
        ]);
        $bridge->save();
        $this->audit->record('zkteco.bridge_created', $bridge, new: ['unit_id' => $unitId, 'name' => $name, 'token_hint' => $bridge->token_hint],
            actor: $actor, organizationId: $company->getKey());

        return [$bridge, $token];
    }

    /** Stop a bridge's token for good; its devices are read by the app again (switch them off if it cannot reach them). */
    public function revoke(Organization $company, Bridge $bridge, User $actor): Bridge
    {
        return $this->place->transaction($company, function () use ($company, $bridge, $actor) {
            $bridge->forceFill(['revoked_at' => now()])->save();
            $this->place->query(Device::class, $company)->where('bridge_id', $bridge->getKey())->update(['bridge_id' => null]);
            $this->audit->record('zkteco.bridge_revoked', $bridge, old: ['name' => $bridge->name, 'token_hint' => $bridge->token_hint], actor: $actor, organizationId: $company->getKey());

            return $bridge;
        });
    }

    /**
     * The company and bridge a token names, or null (unknown, revoked, malformed: all the same).
     *
     * @return array{0: Organization, 1: Bridge}|null
     */
    public function authenticate(?string $token): ?array
    {
        if ($token === null || preg_match('/^zkb\.([0-9a-hjkmnp-tv-z]{26})\.[A-Za-z0-9]{'.self::SECRET_LENGTH.'}$/i', $token, $match) !== 1) {
            return null;
        }
        $company = Organization::query()->find(strtolower($match[1]));
        if ($company === null) {
            return null;
        }
        $bridge = Bridge::inTenantOf($company)->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $company->getKey())->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();

        return $bridge === null ? null : [$company, $bridge];
    }
}
