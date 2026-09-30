<?php

namespace App\Platform\Monitoring;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Services\SessionTracker;
use App\Platform\Offline\Models\Device;
use App\Platform\Offline\Services\DeviceService;
use App\Platform\PartnerApi\Models\PartnerApiKey;
use App\Platform\PartnerApi\Services\ApiKeyService;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use App\Platform\Tenancy\Models\PartnerUser;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Cuts access during an incident (Phase 9-2, docs/incident-playbook.md):
 *
 *  - a person: every API token, every browser session, every offline device;
 *  - an organization (and its units): API tokens for it, browser sessions of
 *    its members (everywhere: a browser session is not per organization),
 *    its offline devices;
 *  - a partner: partner console tokens, partner API keys, its staff's sessions.
 *
 * Nothing is deleted except tokens; people sign in again (with their second
 * step) once the incident is over. Every run is audited with the counts.
 */
class IncidentRevocation
{
    public function __construct(
        private SessionTracker $sessions,
        private DeviceService $devices,
        private ApiKeyService $apiKeys,
        private AuditLogger $audit,
    ) {}

    /**
     * @return array<string, int>
     */
    public function user(User $user, string $reason): array
    {
        $counts = DB::transaction(fn () => [
            'tokens' => $user->tokens()->delete(),
            'sessions' => $this->sessions->endAll($user),
            'devices' => $this->revokeDevices(Device::query()->where('user_id', $user->getKey()), $reason),
        ]);

        $this->audit->record(action: 'security.access_revoked', target: $user, new: ['scope' => 'user', ...$counts], reason: $reason);

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public function organization(Organization $organization, string $reason): array
    {
        $units = Organization::query()->subtreeOf($organization)->pluck('id')->all();

        $counts = DB::transaction(function () use ($units, $reason) {
            $members = User::query()->whereIn('id', DB::table('organization_user')->whereIn('organization_id', $units)->select('user_id'))->get();

            return [
                'tokens' => PersonalAccessToken::query()->whereIn('organization_id', $units)->delete(),
                'sessions' => $members->sum(fn (User $member) => $this->sessions->endAll($member)),
                'devices' => $this->revokeDevices(Device::query()->whereIn('organization_id', $units), $reason),
            ];
        });

        $this->audit->record(
            action: 'security.access_revoked',
            target: $organization,
            new: ['scope' => 'organization', ...$counts],
            reason: $reason,
            organizationId: $organization->getKey(),
            partnerId: $organization->partner_id,
        );

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public function partner(Partner $partner, string $reason): array
    {
        $counts = DB::transaction(function () use ($partner) {
            $keys = PartnerApiKey::query()->where('partner_id', $partner->getKey())->whereNull('revoked_at')->get();
            $keys->each(fn (PartnerApiKey $key) => $this->apiKeys->revoke($key, null));
            $staff = User::query()->whereIn('id', PartnerUser::query()->where('partner_id', $partner->getKey())->select('user_id'))->get();

            return [
                'tokens' => PersonalAccessToken::query()->where('partner_id', $partner->getKey())->delete(),
                'api_keys' => $keys->count(),
                'sessions' => $staff->sum(fn (User $member) => $this->sessions->endAll($member)),
            ];
        });

        $this->audit->record(action: 'security.access_revoked', target: $partner, new: ['scope' => 'partner', ...$counts], reason: $reason, partnerId: $partner->getKey());

        return $counts;
    }

    private function revokeDevices($query, string $reason): int
    {
        $devices = $query->whereNull('revoked_at')->get();
        $devices->each(fn (Device $device) => $this->devices->revoke($device, null, $reason));

        return $devices->count();
    }
}
