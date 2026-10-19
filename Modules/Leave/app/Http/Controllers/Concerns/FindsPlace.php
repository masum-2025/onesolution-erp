<?php

namespace Modules\Leave\Http\Controllers\Concerns;

use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Hrm\Directory\EmployeeRecord;
use Modules\Leave\Exceptions\LeaveException;
use Modules\Leave\Models\LeaveRequest;
use Modules\Leave\Models\LeaveType;
use Modules\Leave\Services\Place;

/**
 * The unit in the address (visible to the context), its company, and
 * records found only inside them; anything else is the same 404.
 */
trait FindsPlace
{
    use FindsVisibleOrganizations;

    /**
     * @return array{0: Organization, 1: Organization} The unit in the address and its company.
     */
    protected function place(string $organization): array
    {
        $unit = $this->findVisible($organization);

        return [$unit, app(Place::class)->companyOf($unit)];
    }

    /** An employee of the company working in the unit or below it. */
    protected function employeeIn(Organization $unit, Organization $company, string $id): EmployeeRecord
    {
        $employee = app(EmployeeDirectory::class)->find($company, $id);
        if ($employee === null || ! $this->within($unit, $employee->unitId)) {
            throw LeaveException::employeeNotFound();
        }

        return $employee;
    }

    protected function typeIn(Organization $company, string $id): LeaveType
    {
        return app(Place::class)->query(LeaveType::class, $company)->whereKey($id)->first() ?? throw LeaveException::typeNotFound();
    }

    protected function requestIn(Organization $unit, Organization $company, string $id): LeaveRequest
    {
        $request = app(Place::class)->query(LeaveRequest::class, $company)->whereKey($id)->first();
        if ($request === null || ! $this->within($unit, $request->unit_id)) {
            throw LeaveException::requestNotFound();
        }

        return $request;
    }

    protected function unitOf(string $id): Organization
    {
        return Organization::query()->findOrFail($id);
    }

    protected function within(Organization $unit, string $id): bool
    {
        return $id === $unit->getKey() || in_array($id, app(Place::class)->subtreeIds($unit), true);
    }
}
