<?php

namespace Modules\Zkteco\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Zkteco\Exceptions\ZktecoException;
use Modules\Zkteco\Models\Bridge;
use Modules\Zkteco\Models\Device;
use Modules\Zkteco\Services\Bridges;
use Modules\Zkteco\Services\Place;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Office bridges at a unit, for people: list them (with the devices each
 * reads and when it last called), make one (its token is in the answer
 * this once only), revoke one. Reading needs zkteco.view; the rest .manage.
 */
class BridgeController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(private Place $place, private Bridges $bridges) {}

    public function index(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('zkteco.view', $unit);
        $bridges = $this->place->query(Bridge::class, $company)->whereIn('unit_id', $this->place->subtreeIds($unit))->orderByDesc('created_at')->get();
        $devices = $this->place->query(Device::class, $company)->whereIn('bridge_id', $bridges->modelKeys())->get(['id', 'bridge_id', 'name'])->groupBy('bridge_id');
        $program = self::programPath();

        return response()->json([
            'data' => $bridges->map(fn (Bridge $bridge) => [
                ...$this->present($bridge),
                'devices' => collect($devices[$bridge->getKey()] ?? [])->map(fn (Device $device) => ['id' => $device->getKey(), 'name' => $device->name])->values(),
            ])->values(),
            'meta' => ['program' => is_file($program) ? ['size' => filesize($program), 'sha256' => hash_file('sha256', $program)] : null],
        ]);
    }

    public function store(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('zkteco.manage', $unit);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'unit_id' => ['nullable', 'string', 'size:26'],
        ]);
        $unitId = $data['unit_id'] ?? $unit->getKey();
        if (! in_array($unitId, $this->place->subtreeIds($unit), true)) {
            throw ValidationException::withMessages(['unit_id' => __('zkteco::zkteco.validation.unit')]);
        }
        [$bridge, $token] = $this->bridges->create($company, $unitId, $data['name'], $request->user());

        // The token, this once: it is not kept and cannot be shown again.
        return response()->json(['data' => [...$this->present($bridge), 'token' => $token, 'server' => rtrim((string) config('app.url'), '/')]], 201);
    }

    public function revoke(Request $request, string $organization, string $bridge): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('zkteco.manage', $unit);
        $found = $this->place->query(Bridge::class, $company)->whereKey($bridge)->whereIn('unit_id', $this->place->subtreeIds($unit))->whereNull('revoked_at')->first()
            ?? throw ZktecoException::notFound('bridge');

        return response()->json(['data' => $this->present($this->bridges->revoke($company, $found, $request->user()))]);
    }

    /** The bridge program (zkteco-bridge.exe, built by Modules/Zkteco/bridge/build.ps1), with its SHA-256 to check it by. */
    public function program(string $organization): BinaryFileResponse
    {
        [$unit] = $this->workplace($organization);
        Gate::authorize('zkteco.manage', $unit);
        $file = self::programPath();
        abort_unless(is_file($file), 404);

        return response()->download($file, 'zkteco-bridge.exe', [
            'Content-Type' => 'application/vnd.microsoft.portable-executable',
            'X-Content-SHA256' => hash_file('sha256', $file),
        ]);
    }

    public static function programPath(): string
    {
        return dirname(__DIR__, 3).'/bridge/dist/zkteco-bridge.exe';
    }

    /**
     * @return array{0: Organization, 1: Organization}
     */
    private function workplace(string $organization): array
    {
        $unit = $this->findVisible($organization);

        return [$unit, $this->place->companyOf($unit)];
    }

    /** @return array<string, mixed> */
    private function present(Bridge $bridge): array
    {
        return [
            'id' => $bridge->getKey(), 'unit_id' => $bridge->unit_id, 'name' => $bridge->name, 'token_hint' => $bridge->token_hint,
            'last_seen_at' => $bridge->last_seen_at?->toIso8601String(), 'last_ip' => $bridge->last_ip,
            'revoked_at' => $bridge->revoked_at?->toIso8601String(), 'created_at' => $bridge->created_at?->toIso8601String(),
        ];
    }
}
