<?php

namespace Modules\Zkteco\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Zkteco\Models\Bridge;
use Modules\Zkteco\Models\Device;
use Modules\Zkteco\Services\Bridges;
use Modules\Zkteco\Services\DeviceSync;
use Modules\Zkteco\Services\Place;

/**
 * What an office bridge (zkteco-bridge.exe) talks to, with its token
 * ("Authorization: Bearer zkb…") and nothing else: the devices it reads,
 * what it read from one, and why one could not be read. A bad or revoked
 * token is 401; HRM, attendance or ZKTeco off for the company is 403; a
 * device not given to this bridge is 404.
 */
class BridgeApiController extends Controller
{
    /** Users and logs one call may carry (the bridge sends more in several calls). */
    public const MAX_USERS = 5000;

    public const MAX_LOGS = 5000;

    private const REASONS = ['no_answer', 'wrong_comm_key', 'refused', 'read_failed'];

    public function __construct(private Bridges $bridges, private Place $place, private DeviceSync $sync, private ModuleResolver $modules) {}

    /** The devices this bridge reads: where they are, their comm key, and from which log time to send. */
    public function devices(Request $request): JsonResponse
    {
        [$company, $bridge] = $this->bridge($request);
        $devices = $this->place->query(Device::class, $company)->where('bridge_id', $bridge->getKey())->where('is_active', true)->orderBy('name')->get();

        return response()->json(['data' => $devices->map(fn (Device $device) => [
            'id' => $device->getKey(), 'name' => $device->name, 'host' => $device->host, 'port' => $device->port, 'protocol' => $device->protocol,
            // Only to the bridge that reads it, over HTTPS.
            'comm_key' => (int) ($device->comm_key ?? 0),
            'import_from' => $device->import_from->toDateString(),
            'since' => $this->sync->keptUntil($company, $device),
        ])->values(), 'meta' => ['bridge' => $bridge->name, 'max_logs' => self::MAX_LOGS]]);
    }

    /** What the bridge read from a device: facts (first batch), users (first batch) and logs newer than "since". */
    public function readings(Request $request, string $device): JsonResponse
    {
        [$company, $bridge] = $this->bridge($request);
        $found = $this->device($company, $bridge, $device);
        $data = $request->validate([
            'facts' => ['nullable', 'array:serial,model,users,logs'],
            'facts.serial' => ['nullable', 'string', 'max:40'],
            'facts.model' => ['nullable', 'string', 'max:60'],
            'facts.users' => ['required_with:facts', 'integer', 'min:0'],
            'facts.logs' => ['required_with:facts', 'integer', 'min:0'],
            'users' => ['present', 'array', 'max:'.self::MAX_USERS],
            'users.*' => ['array:uid,user_id,name'],
            'users.*.uid' => ['required', 'integer', 'min:0'],
            'users.*.user_id' => ['required', 'string', 'max:24'],
            'users.*.name' => ['nullable', 'string', 'max:40'],
            'logs' => ['present', 'array', 'max:'.self::MAX_LOGS],
            'logs.*' => ['array:user_id,at,status,punch'],
            'logs.*.user_id' => ['required', 'string', 'max:24'],
            'logs.*.at' => ['required', 'date_format:Y-m-d H:i:s'],
            'logs.*.status' => ['required', 'integer', 'min:0', 'max:65535'],
            'logs.*.punch' => ['required', 'integer', 'min:0', 'max:65535'],
        ]);
        $users = array_map(fn (array $user) => ['uid' => $user['uid'], 'user_id' => $user['user_id'], 'name' => $user['name'] ?? ''], $data['users']);
        $facts = isset($data['facts']) ? ['serial' => $data['facts']['serial'] ?? null, 'model' => $data['facts']['model'] ?? null, 'users' => $data['facts']['users'], 'logs' => $data['facts']['logs']] : null;
        $result = $this->sync->ingest($company, $found, $facts, $users, $data['logs'], null);

        return response()->json(['data' => [...$result, 'since' => $this->sync->keptUntil($company, $found)]]);
    }

    /** The bridge could not read a device: why, as a code. */
    public function error(Request $request, string $device): JsonResponse
    {
        [$company, $bridge] = $this->bridge($request);
        $found = $this->device($company, $bridge, $device);
        $reason = $request->validate(['reason' => ['required', 'in:'.implode(',', self::REASONS)]])['reason'];
        $found->forceFill(['last_attempt_at' => now(), 'last_error' => $reason])->save();

        return response()->json(null, 204);
    }

    /**
     * The company and bridge of the token, noted as seen; 401 / 403 otherwise.
     *
     * @return array{0: Organization, 1: Bridge}
     */
    private function bridge(Request $request): array
    {
        $found = $this->bridges->authenticate($request->bearerToken());
        if ($found === null) {
            abort(response()->json(['message' => __('zkteco::zkteco.errors.bad_token'), 'code' => 'bad_token'], 401));
        }
        [$company, $bridge] = $found;
        foreach (['hrm', 'attendance', 'zkteco'] as $module) {
            if (! $this->modules->isEnabled($module, $company)) {
                abort(response()->json(['message' => __('zkteco::zkteco.errors.module_off'), 'code' => 'module_off'], 403));
            }
        }
        $bridge->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->save();

        return [$company, $bridge];
    }

    private function device(Organization $company, Bridge $bridge, string $id): Device
    {
        return $this->place->query(Device::class, $company)->whereKey($id)->where('bridge_id', $bridge->getKey())->where('is_active', true)->first()
            ?? abort(response()->json(['message' => __('zkteco::zkteco.errors.device_not_found'), 'code' => 'device_not_found'], 404));
    }
}
