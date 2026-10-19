<?php

namespace Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Hrm\Directory\EmployeeRecord;
use Modules\Leave\Exceptions\LeaveException;
use Modules\Leave\Http\Controllers\Concerns\FindsPlace;
use Modules\Leave\Http\LeavePresenter;
use Modules\Leave\Http\Requests\AskLeaveRequest;
use Modules\Leave\Http\Requests\DecideLeaveRequest;
use Modules\Leave\Models\LeaveRequest;
use Modules\Leave\Models\LeaveType;
use Modules\Leave\Services\Balances;
use Modules\Leave\Services\Place;
use Modules\Leave\Services\Requests;

/**
 * An employee's own leave, through the login HR linked to them: balances
 * this leave year, their requests, asking (leave.request where they signed
 * in) and withdrawing. Only their own records, ever.
 */
class MeController extends Controller
{
    use FindsPlace;

    public function __construct(
        private Place $place,
        private Requests $requests,
        private Balances $balances,
        private EmployeeDirectory $directory,
        private LeavePresenter $presenter,
    ) {}

    public function show(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        $employee = $this->me($company, $request);
        $year = $this->balances->yearOf($this->unitOf($employee->unitId), $this->place->today($company));
        $mine = $this->place->query(LeaveRequest::class, $company)->where('employee_id', $employee->id)
            ->orderByDesc('from_date')->limit(50)->get();
        $types = $this->place->query(LeaveType::class, $company)->get()->keyBy(fn (LeaveType $type) => $type->getKey());

        return response()->json(['data' => [
            'employee' => ['id' => $employee->id, 'name' => $employee->name, 'code' => $employee->code],
            'year' => $year,
            'balances' => array_map(fn (array $row) => $this->presenter->balance($row), $this->balances->forEmployee($company, $employee, $year)),
            'requests' => $mine->map(fn (LeaveRequest $item) => $this->presenter->request($item, null, $types[$item->type_id] ?? null, false, true))->values(),
            'can_request' => Gate::allows('leave.request', $unit),
        ]]);
    }

    public function ask(AskLeaveRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->place($organization);
        Gate::authorize('leave.request', $unit);
        abort_if($request->has('employee_id'), 422, __('leave::leave.validation.own_only'));
        $employee = $this->me($company, $request);

        $created = $this->requests->ask($company, $employee, $request->validated(), $request->user());
        $type = $this->typeIn($company, $created->type_id);

        return response()->json(['data' => $this->presenter->request($created, null, $type, false, true)], 201);
    }

    public function cancel(DecideLeaveRequest $request, string $organization, string $leave): JsonResponse
    {
        [, $company] = $this->place($organization);
        $employee = $this->me($company, $request);
        $found = $this->place->query(LeaveRequest::class, $company)->whereKey($leave)->where('employee_id', $employee->id)->first()
            ?? throw LeaveException::requestNotFound();

        $cancelled = $this->requests->cancel($company, $found, (int) $request->validated('base_version'), (string) $request->validated('note'), $request->user());

        return response()->json(['data' => $this->presenter->request($cancelled, null, $this->typeIn($company, $cancelled->type_id), false, true)]);
    }

    private function me(Organization $company, Request $request): EmployeeRecord
    {
        return $this->directory->forUser($company, $request->user()) ?? throw LeaveException::notLinked();
    }
}
