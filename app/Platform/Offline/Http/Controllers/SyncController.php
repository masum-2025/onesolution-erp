<?php

namespace App\Platform\Offline\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Offline\Exceptions\OfflineException;
use App\Platform\Offline\Http\Requests\SyncRequest;
use App\Platform\Offline\Models\Device;
use App\Platform\Offline\Services\DeviceService;
use App\Platform\Offline\Services\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where devices sync (Phase 7). Signed in, but deliberately outside the
 * organization middleware: a removed device or an ended membership must
 * still reach the server to hand over its changes (held for an admin) and
 * be told to wipe. The device and its lease decide the organization, never
 * the request.
 */
class SyncController extends Controller
{
    public function sync(SyncRequest $request, SyncService $sync): JsonResponse
    {
        $answer = $sync->sync(
            $request->user(),
            $request->validated('device_id'),
            $request->validated('lease'),
            array_values($request->validated('operations')),
            $request->validated('cursor'),
        );

        return response()->json($answer['body'], $answer['status']);
    }

    /** The device cleared its offline data after being told to. */
    public function wiped(Request $request, string $device, DeviceService $devices): JsonResponse
    {
        $found = Device::query()->whereKey($device)->where('user_id', $request->user()->getKey())->first()
            ?? throw OfflineException::deviceNotFound();

        $devices->confirmWiped($found);

        return response()->json(['data' => ['wiped' => $found->wiped_at !== null]]);
    }
}
