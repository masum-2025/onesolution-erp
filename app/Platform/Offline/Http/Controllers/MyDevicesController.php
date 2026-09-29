<?php

namespace App\Platform\Offline\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Offline\Exceptions\OfflineException;
use App\Platform\Offline\Models\Device;
use App\Platform\Offline\Services\DeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The person's own offline devices, in any context (My account): see them,
 * and remove one they lost or no longer use.
 */
class MyDevicesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $devices = Device::query()->with('organization')
            ->where('user_id', $request->user()->getKey())
            ->whereNull('revoked_at')
            ->latest('last_seen_at')->get();

        return response()->json(['data' => $devices->map(fn (Device $device) => [
            'id' => $device->getKey(),
            'name' => $device->name,
            'platform' => $device->platform,
            'organization' => $device->organization?->displayName(),
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'last_sync_at' => $device->last_sync_at?->toIso8601String(),
        ])->values()]);
    }

    public function destroy(Request $request, string $device, DeviceService $devices): JsonResponse
    {
        $found = Device::query()->whereKey($device)->where('user_id', $request->user()->getKey())->first()
            ?? throw OfflineException::deviceNotFound();

        $devices->revoke($found, $request->user(), 'Removed by its owner.');

        return response()->json(['message' => __('offline.messages.device_revoked')]);
    }
}
