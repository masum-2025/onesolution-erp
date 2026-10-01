<?php

namespace Modules\Hrm\Http\Controllers\Concerns;

use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Modules\Hrm\Exceptions\HrmException;
use Modules\Hrm\Models\Employee;

/**
 * Records are found only inside the organization in the address and what
 * the context may see; anything else is the same 404 (no guessing).
 */
trait FindsHrmRecords
{
    use FindsVisibleOrganizations;

    protected function employeeIn(Organization $organization, string $id): Employee
    {
        $employee = Employee::query()->with(['position', 'manager'])->whereKey($id)->first();

        if ($employee === null || ! $this->inside($organization, $employee->organization_id)) {
            throw HrmException::notFound();
        }

        return $employee;
    }

    /** The unit (visible to the context) and inside the organization in the address. */
    protected function unitIn(Organization $organization, ?string $id): Organization
    {
        if ($id === null || $id === $organization->getKey()) {
            return $organization;
        }

        $unit = $this->findVisible($id);
        if (! str_starts_with((string) $unit->path, (string) $organization->path)) {
            throw HrmException::notCompanyUnit();
        }

        return $unit;
    }

    protected function inside(Organization $organization, string $organizationId): bool
    {
        return $organizationId === $organization->getKey()
            || str_starts_with((string) Organization::query()->whereKey($organizationId)->value('path'), (string) $organization->path);
    }

    /**
     * @return list<string> The organization and every unit below it.
     */
    protected function subtreeIds(Organization $organization): array
    {
        return Organization::query()->subtreeOf($organization)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }
}
