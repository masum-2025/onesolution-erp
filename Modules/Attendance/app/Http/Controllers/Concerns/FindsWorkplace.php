<?php

namespace Modules\Attendance\Http\Controllers\Concerns;

use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Modules\Attendance\Exceptions\AttendanceException;
use Modules\Attendance\Services\Workplace;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Hrm\Directory\EmployeeRecord;

/**
 * The unit in the address (visible to the context), its company, and
 * records found only inside them; anything else is the same 404.
 */
trait FindsWorkplace
{
    use FindsVisibleOrganizations;

    /**
     * @return array{0: Organization, 1: Organization} The unit in the address and its company.
     */
    protected function workplace(string $organization): array
    {
        $unit = $this->findVisible($organization);

        return [$unit, app(Workplace::class)->companyOf($unit)];
    }

    /** An employee of the company working in the unit or below it. */
    protected function employeeIn(Organization $unit, Organization $company, string $id): EmployeeRecord
    {
        $employee = app(EmployeeDirectory::class)->find($company, $id);
        if ($employee === null || ! $this->within($unit, $employee->unitId)) {
            throw AttendanceException::employeeNotFound();
        }

        return $employee;
    }

    /**
     * A record of the company by id (unit_id, when it has one, inside the unit).
     *
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @return T
     */
    protected function recordIn(string $model, Organization $unit, Organization $company, string $id, AttendanceException $missing): Model
    {
        $record = app(Workplace::class)->query($model, $company)->whereKey($id)->first();
        if ($record === null || ($record->unit_id !== null && ! $this->within($unit, $record->unit_id))) {
            throw $missing;
        }

        return $record;
    }

    protected function unitOf(string $id): Organization
    {
        return Organization::query()->findOrFail($id);
    }

    protected function within(Organization $unit, string $id): bool
    {
        return $id === $unit->getKey() || in_array($id, app(Workplace::class)->subtreeIds($unit), true);
    }
}
