<?php

namespace App\Platform\Offline\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Offline\Exceptions\OfflineException;
use App\Platform\Offline\Http\Requests\OfflineReasonRequest;
use App\Platform\Offline\Models\Device;
use App\Platform\Offline\Models\QuarantinedOperation;
use App\Platform\Offline\Services\DeviceService;
use App\Platform\Offline\Services\QuarantineService;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * An organization's offline devices and held changes (Phase 7), for people
 * with offline_mode.manage: remove a device (it wipes itself), release or
 * discard a held change. The organization and the units below it only.
 */
class OfflineAdminController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(private DeviceService $devices, private QuarantineService $quarantine) {}

    public function show(string $organization): JsonResponse
    {
        $organization = $this->organization($organization);
        $units = $this->units($organization);

        $devices = Device::query()->with(['user', 'organization'])->whereIn('organization_id', $units)->whereNull('revoked_at')->latest('last_seen_at')->limit(500)->get();
        $held = QuarantinedOperation::query()->with(['user', 'device'])->whereIn('organization_id', $units)
            ->where('status', QuarantinedOperation::PENDING)->oldest()->limit(500)->get();

        return response()->json(['data' => [
            'devices' => $devices->map(fn (Device $device) => [
                'id' => $device->getKey(),
                'name' => $device->name,
                'platform' => $device->platform,
                'person' => $device->user?->name,
                'unit' => $device->organization?->displayName(),
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                'last_sync_at' => $device->last_sync_at?->toIso8601String(),
            ])->values(),
            'held' => $held->map(fn (QuarantinedOperation $operation) => [
                'id' => $operation->getKey(),
                'kind' => $operation->kind,
                'action' => $operation->action,
                'money' => $operation->money,
                'reason' => $operation->reason,
                'reason_text' => __("offline.results.{$operation->reason}"),
                'person' => $operation->user?->name,
                'device' => $operation->device?->name,
                'received_at' => $operation->created_at->toIso8601String(),
                'expires_at' => $operation->expires_at->toIso8601String(),
            ])->values(),
        ]]);
    }

    public function revoke(OfflineReasonRequest $request, string $organization, string $device): JsonResponse
    {
        $organization = $this->organization($organization);
        $found = Device::query()->whereIn('organization_id', $this->units($organization))->whereKey($device)->first()
            ?? throw OfflineException::deviceNotFound();

        $this->devices->revoke($found, $request->user(), $request->validated('reason'));

        return response()->json(['message' => __('offline.messages.device_revoked')]);
    }

    public function release(Request $request, string $organization, string $held): JsonResponse
    {
        $found = $this->held($organization, $held);
        $result = $this->quarantine->release($found, $request->user());

        return response()->json(['data' => $result->toArray(), 'message' => __('offline.messages.released')]);
    }

    public function discard(OfflineReasonRequest $request, string $organization, string $held): JsonResponse
    {
        $found = $this->held($organization, $held);
        $this->quarantine->discard($found, $request->user(), $request->validated('reason'));

        return response()->json(['message' => __('offline.messages.discarded')]);
    }

    private function held(string $organization, string $id): QuarantinedOperation
    {
        $organization = $this->organization($organization);

        return QuarantinedOperation::query()->whereIn('organization_id', $this->units($organization))->whereKey($id)->first()
            ?? throw OfflineException::quarantineNotFound();
    }

    private function organization(string $id): Organization
    {
        $organization = $this->findVisible($id);
        Gate::authorize('offline_mode.manage', $organization);

        return $organization;
    }

    /**
     * @return list<string>
     */
    private function units(Organization $organization): array
    {
        return Organization::query()->subtreeOf($organization)->pluck('id')->all();
    }
}
