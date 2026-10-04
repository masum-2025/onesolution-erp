<?php

namespace Modules\Payroll\Http\Controllers\Concerns;

use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Hrm\Directory\EmployeeRecord;
use Modules\Payroll\Services\Payrolls;

/**
 * The unit in the address (visible to the context), its company, and
 * employees only inside the unit; anything else is the same 404.
 */
trait FindsPayroll
{
    use FindsVisibleOrganizations;

    /**
     * @return array{0: Organization, 1: Organization}
     */
    protected function workplace(string $organization): array
    {
        $unit = $this->findVisible($organization);

        return [$unit, app(Payrolls::class)->companyOf($unit)];
    }

    protected function employeeIn(Organization $unit, Organization $company, string $id, \Throwable $missing): EmployeeRecord
    {
        $employee = app(EmployeeDirectory::class)->find($company, $id);
        if ($employee === null || ! in_array($employee->unitId, app(Payrolls::class)->subtreeIds($unit), true)) {
            throw $missing;
        }

        return $employee;
    }

    protected function unitOf(string $id): Organization
    {
        return Organization::query()->findOrFail($id);
    }
}
