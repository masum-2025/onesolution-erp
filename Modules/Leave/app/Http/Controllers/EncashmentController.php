<?php

namespace Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Leave\Exceptions\LeaveException;
use Modules\Leave\Http\Controllers\Concerns\FindsPlace;
use Modules\Leave\Http\LeavePresenter;
use Modules\Leave\Http\Requests\EncashmentRequest;
use Modules\Leave\Models\LeaveEncashment;
use Modules\Leave\Models\LeaveType;
use Modules\Leave\Services\Encashments;
use Modules\Leave\Services\Place;

/**
 * Unused leave paid out at a unit (and below it): the list (leave.view),
 * asking (leave.manage), deciding (leave.approve; never one's own ask nor
 * one's own leave). Payroll pays approved ones in the run of their day.
 */
class EncashmentController extends Controller
{
    use FindsPlace;

    public function __construct(
        private Place $place,
        private Encashments $encashments,
        private EmployeeDirectory $directory,
        private LeavePresenter $presenter,
    ) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        abort_unless(Gate::any(['leave.view', 'leave.approve'], $unit), 403);
        $status = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected']])['status'] ?? null;

        $list = $this->place->query(LeaveEncashment::class, $company)->whereIn('unit_id', $this->place->subtreeIds($unit))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')->limit(200)->get()
            ->sortBy(fn (LeaveEncashment $item) => $item->status === LeaveEncashment::PENDING ? 0 : 1)->values();
        $employees = $this->directory->many($company, $list->pluck('employee_id')->unique()->values()->all());
        $types = $this->place->query(LeaveType::class, $company)->get()->keyBy(fn (LeaveType $type) => $type->getKey());

        return response()->json(['data' => $list->map(fn (LeaveEncashment $item) => $this->presenter->encashment(
            $item,
            $employees[$item->employee_id] ?? null,
            $types[$item->type_id] ?? null,
            $this->encashments->canDecide($company, $item, $request->user(), $employees[$item->employee_id] ?? null) && Gate::allows('leave.approve', $this->unitOf($item->unit_id)),
        ))->values()]);
    }

    public function store(EncashmentRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $data = $request->validated();
        $employee = $this->employeeIn($unit, $company, $data['employee_id']);
        Gate::authorize('leave.manage', $this->unitOf($employee->unitId));
        $type = $this->typeIn($company, $data['type_id']);

        $created = $this->encashments->ask($company, $employee, $type, (int) $data['leave_year'], (int) $data['halves'], $data['reason'], $request->user());

        return response()->json(['data' => $this->presenter->encashment($created, $employee, $type, false)], 201);
    }

    public function decide(EncashmentRequest $request, string $organization, string $encashment, string $step): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $data = $request->validated();
        $found = $this->place->query(LeaveEncashment::class, $company)->whereKey($encashment)->first();
        if ($found === null || ! $this->within($unit, $found->unit_id)) {
            throw LeaveException::encashmentNotFound();
        }
        Gate::authorize('leave.approve', $this->unitOf($found->unit_id));

        $decided = match ($step) {
            'approve' => $this->encashments->approve($company, $found, (int) $data['base_version'], $data['note'] ?? null, $request->user()),
            'reject' => $this->encashments->reject($company, $found, (int) $data['base_version'], $data['note'] ?? null, $request->user()),
            default => abort(404),
        };

        return response()->json(['data' => $this->presenter->encashment($decided, $this->directory->find($company, $decided->employee_id), $this->typeIn($company, $decided->type_id), false)]);
    }
}
