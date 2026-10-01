<?php

namespace Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Hrm\Exceptions\HrmException;
use Modules\Hrm\Http\Controllers\Concerns\FindsHrmRecords;
use Modules\Hrm\Http\EmployeePresenter;
use Modules\Hrm\Http\Requests\EmploymentStepRequest;
use Modules\Hrm\Services\EmployeeLifecycle;

/**
 * Employment steps: confirm, transfer, promote, rehire (hrm.manage) and
 * notice, exit (hrm.exit), each at the employee's unit; a transfer also
 * needs hrm.manage at the unit they move to.
 */
class EmploymentStepController extends Controller
{
    use FindsHrmRecords;

    public function __construct(private EmployeeLifecycle $lifecycle, private EmployeePresenter $presenter) {}

    public function __invoke(EmploymentStepRequest $request, string $organization, string $employee, string $step): JsonResponse
    {
        $employee = $this->employeeIn($this->findVisible($organization), $employee);
        if (! in_array($step, EmploymentStepRequest::STEPS, true)) {
            throw HrmException::unknownStep();
        }
        $unit = Organization::query()->findOrFail($employee->organization_id);
        Gate::authorize(in_array($step, ['notice', 'exit'], true) ? 'hrm.exit' : 'hrm.manage', $unit);

        $data = $request->validated();
        $version = (int) $data['base_version'];
        $to = isset($data['to_organization_id']) ? $this->findVisible($data['to_organization_id']) : null;
        if ($to !== null) {
            Gate::authorize('hrm.manage', $to);
        }

        $updated = match ($step) {
            'confirm' => $this->lifecycle->confirm($employee, $version, $data, $request->user()),
            'transfer' => $this->lifecycle->transfer($employee, $version, $to, $data, $request->user()),
            'promote' => $this->lifecycle->promote($employee, $version, $data['position_id'], $to, $data, $request->user()),
            'notice' => $this->lifecycle->giveNotice($employee, $version, $data, $request->user()),
            'exit' => $this->lifecycle->exit($employee, $version, $data, $request->user()),
            'rehire' => $this->lifecycle->rehire($employee, $version, $to, $data, $request->user()),
        };

        return response()->json(['data' => $this->presenter->detail($updated->load(['position', 'manager']))]);
    }
}
