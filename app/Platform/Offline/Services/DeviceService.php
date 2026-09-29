<?php

namespace App\Platform\Offline\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Offline\Events\DeviceRevoked;
use App\Platform\Offline\Models\Device;
use App\Platform\Offline\Models\OfflineLease;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A person's devices for offline work: registering one, removing it (by
 * the person or an admin), and the device confirming it cleared its data.
 * A removed device gets no lease and no sync; it is told to wipe itself.
 */
class DeviceService
{
    public function __construct(private AuditLogger $audit) {}

    public function register(User $user, Organization $organization, string $name, ?string $platform): Device
    {
        $device = new Device;
        $device->forceFill([
            'user_id' => $user->getKey(),
            'organization_id' => $organization->getKey(),
            'name' => mb_substr(trim($name), 0, 80),
            'platform' => $platform === null ? null : mb_substr($platform, 0, 60),
            'last_seen_at' => CarbonImmutable::now(),
        ])->save();

        $this->audit->record(
            action: 'offline.device_registered',
            target: $device,
            new: ['name' => $device->name, 'platform' => $device->platform],
            actor: $user,
            organizationId: $organization->getKey(),
            partnerId: $organization->partner_id,
        );

        return $device;
    }

    /**
     * Removes a device from offline work: its leases stop at once and it
     * wipes its local data on its next contact.
     */
    public function revoke(Device $device, User $actor, ?string $reason = null): Device
    {
        return DB::transaction(function () use ($device, $actor, $reason) {
            $device = Device::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();
            if ($device->isRevoked()) {
                return $device;
            }

            $now = CarbonImmutable::now();
            $device->forceFill([
                'revoked_at' => $now,
                'revoked_by' => $actor->getKey(),
                'revoke_reason' => $reason === null ? null : mb_substr($reason, 0, 500),
                'wipe_requested_at' => $device->wipe_requested_at ?? $now,
            ])->save();

            OfflineLease::query()->where('device_id', $device->getKey())->whereNull('revoked_at')->update(['revoked_at' => $now]);

            $this->audit->record(
                action: 'offline.device_revoked',
                target: $device,
                new: ['name' => $device->name, 'owner' => $device->user_id],
                reason: $reason,
                actor: $actor,
                organizationId: $device->organization_id,
                partnerId: $device->organization?->partner_id,
            );

            DeviceRevoked::dispatch($device);

            return $device;
        });
    }

    /**
     * Asks every device of these organizations to wipe (e.g. offline mode was
     * turned off there). Their leases stop too.
     *
     * @param  list<string>  $organizationIds
     */
    public function wipeAll(array $organizationIds, ?User $actor, string $reason): int
    {
        $now = CarbonImmutable::now();
        $ids = Device::query()->whereIn('organization_id', $organizationIds)->whereNull('wipe_requested_at')->pluck('id');

        Device::query()->whereKey($ids)->update(['wipe_requested_at' => $now, 'updated_at' => $now]);
        OfflineLease::query()->whereIn('device_id', $ids)->whereNull('revoked_at')->update(['revoked_at' => $now]);

        return $ids->count();
    }

    /** The device cleared its offline data. */
    public function confirmWiped(Device $device): Device
    {
        if ($device->wipe_requested_at !== null && $device->wiped_at === null) {
            $device->forceFill(['wiped_at' => CarbonImmutable::now()])->save();

            $this->audit->record(
                action: 'offline.device_wiped',
                target: $device,
                actor: $device->user,
                organizationId: $device->organization_id,
                partnerId: $device->organization?->partner_id,
            );
        }

        return $device;
    }
}
