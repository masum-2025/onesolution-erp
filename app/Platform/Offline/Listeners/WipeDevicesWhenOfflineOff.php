<?php

namespace App\Platform\Offline\Listeners;

use App\Platform\Audit\AuditLogger;
use App\Platform\Modules\Events\ModuleDisabled;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Offline\Services\DeviceService;
use App\Platform\Tenancy\Models\Organization;

/**
 * offline_mode turned off: every device of the organizations where it is now
 * off must wipe its local data; their leases stop at once, and /sync only
 * holds their changes for an admin. Runs synchronously, like the token
 * revocation for api_integration.
 */
class WipeDevicesWhenOfflineOff
{
    public function __construct(
        private ModuleResolver $resolver,
        private DeviceService $devices,
        private AuditLogger $audit,
    ) {}

    public function handle(ModuleDisabled $event): void
    {
        if ($event->moduleKey !== 'offline_mode') {
            return;
        }

        $off = Organization::query()->subtreeOf($event->organization)->get()
            ->filter(fn (Organization $organization) => ! $this->resolver->isEnabled('offline_mode', $organization))
            ->map->getKey()->values()->all();

        $count = $this->devices->wipeAll($off, $event->actor, 'module_off');

        if ($count > 0) {
            $this->audit->record(
                action: 'offline.devices_wiped',
                target: $event->organization,
                new: ['devices' => $count],
                reason: $event->reason,
                actor: $event->actor,
                organizationId: $event->organization->getKey(),
                partnerId: $event->organization->partner_id,
            );
        }
    }
}
