<?php

namespace Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Portal\PortalAccess;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Leave\Http\LeavePresenter;
use Modules\Leave\Models\LeaveRequest;
use Modules\Leave\Models\LeaveType;
use Modules\Leave\Services\Balances;
use Modules\Leave\Services\Place;

/**
 * A portal member's own leave (B2B2C: an employee who uses the client's
 * portal): balances this leave year and requests of the employee records
 * the client linked to them (portal subject hrm.employee). Read only.
 */
class PortalLeaveController extends Controller
{
    public function __construct(
        private CurrentContext $context,
        private PortalAccess $portal,
        private Place $place,
        private Balances $balances,
        private EmployeeDirectory $directory,
        private LeavePresenter $presenter,
    ) {}

    public function show(): JsonResponse
    {
        if (! $this->portal->isPortal()) {
            return response()->json(['data' => []]);
        }
        $company = $this->place->companyOf($this->context->organization());
        $types = $this->place->query(LeaveType::class, $company)->get()->keyBy(fn (LeaveType $type) => $type->getKey());
        $data = [];
        foreach ($this->directory->many($company, $this->portal->subjectIds('hrm.employee')) as $employee) {
            $unit = Organization::query()->find($employee->unitId);
            if ($unit === null) {
                continue;
            }
            $year = $this->balances->yearOf($unit, $this->place->today($company));
            $data[] = [
                'employee' => ['id' => $employee->id, 'name' => $employee->name],
                'year' => $year,
                'balances' => array_map(fn (array $row) => $this->presenter->balance($row), $this->balances->forEmployee($company, $employee, $year)),
                'requests' => $this->place->query(LeaveRequest::class, $company)->where('employee_id', $employee->id)->orderByDesc('from_date')->limit(50)->get()
                    ->map(fn (LeaveRequest $item) => $this->presenter->request($item, null, $types[$item->type_id] ?? null))->values(),
            ];
        }

        return response()->json(['data' => $data]);
    }
}
