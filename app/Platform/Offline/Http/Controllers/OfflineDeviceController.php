<?php

namespace App\Platform\Offline\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Offline\Exceptions\OfflineException;
use App\Platform\Offline\Http\Requests\RegisterDeviceRequest;
use App\Platform\Offline\Models\Device;
use App\Platform\Offline\Services\DeviceService;
use App\Platform\Offline\Services\LeaseService;
use App\Platform\Tenancy\Context\CurrentContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Setting up a device for offline work and refreshing its lease, inside the
 * person's own organization context (Phase 7). Needs offline_mode.use.
 */
class OfflineDeviceController extends Controller
{
    public function __construct(private CurrentContext $context, private DeviceService $devices, private LeaseService $leases) {}

    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        $organization = $this->context->organization();
        if (! Gate::allows('offline_mode.use', $organization)) {
            throw OfflineException::notAllowed();
        }

        [$device, $lease] = DB::transaction(function () use ($request, $organization) {
            $device = $this->devices->register($request->user(), $organization, $request->validated('name'), $request->validated('platform'));

            return [$device, $this->leases->issue($device)];
        });

        return response()->json(['data' => $this->present($device, $lease), 'message' => __('offline.messages.device_registered')], 201);
    }

    public function lease(string $device): JsonResponse
    {
        $found = Device::query()->whereKey($device)->where('user_id', $this->context->user()->getKey())->first()
            ?? throw OfflineException::deviceNotFound();

        return response()->json(['data' => $this->present($found, $this->leases->issue($found))]);
    }

    /**
     * @param  array{token: string, lease: array<string, mixed>}  $lease
     * @return array<string, mixed>
     */
    private function present(Device $device, array $lease): array
    {
        return [
            'device' => ['id' => $device->getKey(), 'name' => $device->name],
            'lease' => $lease['token'],
            // Readable by the device without checking the signature (only the server trusts it).
            'lease_id' => $lease['lease']['lid'],
            'expires_at' => CarbonImmutable::createFromTimestamp($lease['lease']['exp'])->toIso8601String(),
            'kinds' => $lease['lease']['kinds'],
            'permissions' => $lease['lease']['perms'],
            'rules' => $lease['lease']['rules'],
            'rule_version' => $lease['lease']['rule_version'],
        ];
    }
}
