<?php

namespace Modules\Zkteco\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Zkteco\Exceptions\ZktecoException;
use Modules\Zkteco\Http\Requests\DeviceRequest;
use Modules\Zkteco\Models\Device;
use Modules\Zkteco\Models\DeviceUser;
use Modules\Zkteco\Models\Log;
use Modules\Zkteco\Services\Devices;
use Modules\Zkteco\Services\DeviceSync;
use Modules\Zkteco\Services\Matching;
use Modules\Zkteco\Services\Place;

/**
 * Terminals at a unit (and below it). Reading needs zkteco.view; adding,
 * changing, trying, pulling and matching users need zkteco.manage. The
 * comm key never leaves the server.
 */
class DeviceController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(
        private Place $place,
        private Devices $devices,
        private DeviceSync $sync,
        private Matching $matching,
        private EmployeeDirectory $employees,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /** What the screens need once: what the person may do, the rules, the branches and today's start. */
    public function setup(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('zkteco.view', $unit);
        $units = $this->place->subtreeIds($unit);

        return response()->json(['data' => [
            'can' => ['view' => true, 'manage' => Gate::allows('zkteco.manage', $unit)],
            'rules' => ['pull_minutes' => (int) $this->rules->get('zkteco.pull_minutes', $this->contexts->forOrganization($unit))],
            'units' => Organization::query()->whereKey($units)->whereNot('type', OrganizationType::Department->value)->orderBy('depth')->get()
                ->map(fn (Organization $place) => ['id' => $place->getKey(), 'name' => $place->displayName(), 'type' => $place->type->value])->values(),
            'protocols' => Device::PROTOCOLS,
            'today' => CarbonImmutable::now($this->place->timezone($company))->toDateString(),
        ]]);
    }

    public function index(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('zkteco.view', $unit);
        $devices = $this->place->query(Device::class, $company)->whereIn('unit_id', $this->place->subtreeIds($unit))->orderBy('name')->get();
        $waiting = $this->place->query(Log::class, $company)->whereIn('device_id', $devices->modelKeys())->where('state', 'unmatched')
            ->get(['device_id'])->countBy('device_id');

        return response()->json(['data' => $devices->map(fn (Device $device) => $this->present($device, (int) ($waiting[$device->getKey()] ?? 0)))->values()]);
    }

    public function store(DeviceRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('zkteco.manage', $unit);
        $data = $request->validated();
        $data['unit_id'] ??= $unit->getKey();
        if (! in_array($data['unit_id'], $this->place->subtreeIds($unit), true)) {
            throw ValidationException::withMessages(['unit_id' => __('zkteco::zkteco.validation.unit')]);
        }

        return response()->json(['data' => $this->present($this->devices->save($company, null, $data, $request->user()))], 201);
    }

    public function update(DeviceRequest $request, string $organization, string $device): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('zkteco.manage', $unit);

        return response()->json(['data' => $this->present($this->devices->save($company, $this->find($company, $unit, $device), $request->validated(), $request->user()))]);
    }

    public function destroy(Request $request, string $organization, string $device): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('zkteco.manage', $unit);
        $version = (int) $request->validate(['base_version' => ['required', 'integer', 'min:1']])['base_version'];
        $this->devices->remove($company, $this->find($company, $unit, $device), $version, $request->user());

        return response()->json(null, 204);
    }

    /** Reach the terminal and say what it is; nothing is brought in. */
    public function test(string $organization, string $device): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('zkteco.manage', $unit);
        $found = $this->find($company, $unit, $device);
        if ($found->bridge_id !== null) {
            throw ZktecoException::readByBridge();
        }

        return response()->json(['data' => ['facts' => $this->devices->test($company, $found), 'device' => $this->present($found->refresh())]]);
    }

    /** Pull the terminal now. */
    public function sync(Request $request, string $organization, string $device): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('zkteco.manage', $unit);
        $found = $this->find($company, $unit, $device);
        if ($found->bridge_id !== null) {
            throw ZktecoException::readByBridge();
        }
        $result = $this->sync->pull($company, $found, $request->user());

        return response()->json(['data' => ['result' => $result, 'device' => $this->present($found->refresh())]]);
    }

    /** The terminal's users and the employee each is (name and code), with how many logs wait for each. */
    public function users(string $organization, string $device): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('zkteco.view', $unit);
        $found = $this->find($company, $unit, $device);
        $users = $this->place->query(DeviceUser::class, $company)->where('device_id', $found->getKey())->get()
            ->sortBy(fn (DeviceUser $user) => str_pad($user->user_id, 24, '0', STR_PAD_LEFT))->values();
        $people = $this->employees->many($company, $users->pluck('employee_id')->filter()->unique()->values()->all());
        $waiting = $this->place->query(Log::class, $company)->where('device_id', $found->getKey())->where('state', 'unmatched')->get(['user_id'])->countBy('user_id');
        // Everyone of the company may punch at any of its devices.
        $staff = $this->employees->inUnits($company, $this->place->subtreeIds($company));
        $taken = $users->pluck('employee_id')->filter()->values()->all();

        return response()->json([
            'data' => $users->map(fn (DeviceUser $user) => [
                'id' => $user->getKey(), 'user_id' => $user->user_id, 'name' => $user->name, 'matched_by' => $user->matched_by,
                'employee' => $user->employee_id === null || ! isset($people[$user->employee_id]) ? null
                    : ['id' => $user->employee_id, 'code' => $people[$user->employee_id]->code, 'name' => $people[$user->employee_id]->name],
                // Never suggested for a user set to "nobody" by hand.
                'suggestion' => $user->employee_id === null && $user->matched_by !== 'hand' ? Matching::suggest($user->name, $staff, $taken) : null,
                'waiting' => (int) ($waiting[$user->user_id] ?? 0),
            ])->values(),
            'meta' => ['employees' => collect($staff)->map(fn ($employee) => ['id' => $employee->id, 'code' => $employee->code, 'name' => $employee->name])->values()],
        ]);
    }

    /** Match several users at once (e.g. every suggestion); their waiting logs go once at the end. */
    public function matchMany(Request $request, string $organization, string $device): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('zkteco.manage', $unit);
        $data = $request->validate([
            'matches' => ['required', 'array', 'min:1', 'max:500'],
            'matches.*.user' => ['required', 'string', 'size:26', 'distinct'],
            'matches.*.employee_id' => ['present', 'nullable', 'string', 'size:26'],
        ]);
        $found = $this->find($company, $unit, $device);
        $rows = $this->place->query(DeviceUser::class, $company)->where('device_id', $found->getKey())->whereKey(array_column($data['matches'], 'user'))->get()->keyBy('id');
        if ($rows->count() !== count($data['matches'])) {
            throw ZktecoException::notFound('user');
        }
        $recorded = $this->matching->matchMany($company, $found, array_map(fn (array $match) => [$rows[$match['user']], $match['employee_id']], $data['matches']), $request->user());

        return response()->json(['data' => ['matched' => count($data['matches']), 'recorded' => $recorded]]);
    }

    /** Match a terminal user to an employee by hand (or to nobody); its waiting logs go right away. */
    public function match(Request $request, string $organization, string $device, string $user): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('zkteco.manage', $unit);
        $data = $request->validate(['employee_id' => ['present', 'nullable', 'string', 'size:26']]);
        $found = $this->find($company, $unit, $device);
        $row = $this->place->query(DeviceUser::class, $company)->where('device_id', $found->getKey())->whereKey($user)->first()
            ?? throw ZktecoException::notFound('user');
        $result = $this->matching->match($company, $found, $row, $data['employee_id'], $request->user());

        return response()->json(['data' => ['id' => $row->getKey(), 'employee_id' => $row->employee_id, 'matched_by' => $row->matched_by, 'recorded' => $result['recorded']]]);
    }

    /**
     * @return array{0: Organization, 1: Organization}
     */
    private function workplace(string $organization): array
    {
        $unit = $this->findVisible($organization);

        return [$unit, $this->place->companyOf($unit)];
    }

    private function find(Organization $company, Organization $unit, string $id): Device
    {
        return $this->place->query(Device::class, $company)->whereKey($id)->whereIn('unit_id', $this->place->subtreeIds($unit))->first()
            ?? throw ZktecoException::notFound('device');
    }

    /** @return array<string, mixed> */
    private function present(Device $device, ?int $waiting = null): array
    {
        return [
            'id' => $device->getKey(), 'unit_id' => $device->unit_id, 'bridge_id' => $device->bridge_id, 'name' => $device->name, 'host' => $device->host, 'port' => $device->port,
            'protocol' => $device->protocol, 'has_comm_key' => $device->comm_key !== null, 'import_from' => $device->import_from->toDateString(),
            'is_active' => $device->is_active, 'serial' => $device->serial, 'model' => $device->model, 'users_count' => $device->users_count,
            'logs_count' => $device->logs_count, 'last_attempt_at' => $device->last_attempt_at?->toIso8601String(),
            'last_synced_at' => $device->last_synced_at?->toIso8601String(), 'last_error' => $device->last_error, 'version' => $device->version,
            ...($waiting === null ? [] : ['waiting' => $waiting]),
        ];
    }
}
