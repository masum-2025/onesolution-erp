<?php

namespace Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Leave\Http\Controllers\Concerns\FindsPlace;
use Modules\Leave\Http\LeavePresenter;
use Modules\Leave\Http\Requests\AdjustBalanceRequest;
use Modules\Leave\Http\Requests\AskLeaveRequest;
use Modules\Leave\Http\Requests\DecideLeaveRequest;
use Modules\Leave\Models\LeaveRequest;
use Modules\Leave\Models\LeaveType;
use Modules\Leave\Services\Balances;
use Modules\Leave\Services\Place;
use Modules\Leave\Services\Requests;

/**
 * Leave of the people at a unit (and below it): the requests (leave.view),
 * asking on someone's behalf (leave.manage), deciding a step (leave.approve;
 * never one's own), cancelling, and balances with changes by hand
 * (leave.manage, audited).
 */
class RequestController extends Controller
{
    use FindsPlace;

    public function __construct(
        private Place $place,
        private Requests $requests,
        private Balances $balances,
        private EmployeeDirectory $directory,
        private LeavePresenter $presenter,
    ) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(['leave.view', 'leave.approve'], $unit), 403);
        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected,cancelled'],
            'employee_id' => ['nullable', 'string', 'max:26'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $list = $this->place->query(LeaveRequest::class, $company)->whereIn('unit_id', $this->place->subtreeIds($unit))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['employee_id'] ?? null, fn ($query, $id) => $query->where('employee_id', $id))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('to_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('from_date', '<=', $to))
            ->orderByDesc('from_date')->orderByDesc('created_at')->limit(200)->get()
            ->sortBy(fn (LeaveRequest $item) => $item->status === LeaveRequest::PENDING ? 0 : 1)->values();

        return response()->json(['data' => $this->present($company, $list, $request)]);
    }

    public function show(Request $request, string $organization, string $leave): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(['leave.view', 'leave.approve'], $unit), 403);
        $found = $this->requestIn($unit, $company, $leave);

        return response()->json(['data' => $this->present($company, collect([$found]), $request)[0]]);
    }

    /** HR asks on someone's behalf (a person without a login, or who cannot). */
    public function store(AskLeaveRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $employee = $this->employeeIn($unit, $company, (string) $request->validated('employee_id'));
        Gate::authorize('leave.manage', $this->unitOf($employee->unitId));

        $created = $this->requests->ask($company, $employee, $request->safe()->except('employee_id'), $request->user());

        return response()->json(['data' => $this->present($company, collect([$created]), $request)[0]], 201);
    }

    public function decide(DecideLeaveRequest $request, string $organization, string $leave, string $step): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $found = $this->requestIn($unit, $company, $leave);
        $version = (int) $request->validated('base_version');
        $note = $request->validated('note');

        $decided = match ($step) {
            'approve' => $this->requests->approve($company, $found, $version, $note, $this->authorized('leave.approve', $found, $request)),
            'reject' => $this->requests->reject($company, $found, $version, $note, $this->authorized('leave.approve', $found, $request)),
            'cancel' => $this->requests->cancel($company, $found, $version, (string) $note, $request->user()),
            default => abort(404),
        };

        return response()->json(['data' => $this->present($company, collect([$decided]), $request)[0]]);
    }

    /**
     * People at the unit (and below it) employed today, to pick someone on
     * the leave screens: name and code only (HRM's list needs hrm.view).
     */
    public function people(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(['leave.view', 'leave.manage'], $unit), 403);
        $search = mb_strtolower(trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? '')));

        $people = collect($this->directory->inUnits($company, $this->place->subtreeIds($unit), $this->place->today($company)))
            ->filter(fn ($employee) => $search === '' || str_contains(mb_strtolower($employee->name), $search) || str_contains(mb_strtolower($employee->code), $search))
            ->take(50)->map(fn ($employee) => ['id' => $employee->id, 'name' => $employee->name, 'code' => $employee->code])->values();

        return response()->json(['data' => $people]);
    }

    /** One employee's balances in a leave year (the current one by default). */
    public function balances(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $data = $request->validate(['employee_id' => ['required', 'string', 'max:26'], 'year' => ['nullable', 'integer', 'min:2000', 'max:2100']]);
        $employee = $this->employeeIn($unit, $company, $data['employee_id']);
        $employeeUnit = $this->unitOf($employee->unitId);
        abort_unless(Gate::any(['leave.view', 'leave.approve'], $employeeUnit), 403);
        $year = (int) ($data['year'] ?? $this->balances->yearOf($employeeUnit, $this->place->today($company)));

        return response()->json(['data' => array_map(fn (array $row) => $this->presenter->balance($row), $this->balances->forEmployee($company, $employee, $year)), 'meta' => [
            'year' => $year, 'can_adjust' => Gate::allows('leave.manage', $employeeUnit),
        ]]);
    }

    public function adjust(AdjustBalanceRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $data = $request->validated();
        $employee = $this->employeeIn($unit, $company, $data['employee_id']);
        Gate::authorize('leave.manage', $this->unitOf($employee->unitId));
        $type = $this->typeIn($company, $data['type_id']);

        $this->balances->adjust($company, $employee, $type, (int) $data['leave_year'], (int) $data['halves'], $data['note'], $request->user());

        return response()->json(['data' => ['balance_halves' => $this->balances->balance($company, $employee->id, $type->getKey(), (int) $data['leave_year'])]], 201);
    }

    private function authorized(string $permission, LeaveRequest $found, Request $request): User
    {
        Gate::authorize($permission, $this->unitOf($found->unit_id));

        return $request->user();
    }

    /**
     * @param  Collection<int, LeaveRequest>  $list
     * @return list<array<string, mixed>>
     */
    private function present(Organization $company, Collection $list, Request $request): array
    {
        $employees = $this->directory->many($company, $list->pluck('employee_id')->unique()->values()->all());
        $types = $this->place->query(LeaveType::class, $company)->whereKey($list->pluck('type_id')->unique()->values()->all())->get()->keyBy(fn (LeaveType $type) => $type->getKey());
        $user = $request->user();

        return $list->map(fn (LeaveRequest $item) => $this->presenter->request(
            $item,
            $employees[$item->employee_id] ?? null,
            $types[$item->type_id] ?? null,
            $this->requests->canDecide($company, $item, $user, $employees[$item->employee_id] ?? null),
            $item->requested_by === $user->getKey() || ($employees[$item->employee_id] ?? null)?->userId === $user->getKey(),
        ))->values()->all();
    }
}
