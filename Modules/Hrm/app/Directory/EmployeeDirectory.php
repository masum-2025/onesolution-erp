<?php

namespace Modules\Hrm\Directory;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Hrm\Enums\EmployeeStatus;
use Modules\Hrm\Models\Employee;

/**
 * HRM's public service for other modules: employees of a company, by id, by
 * their login, or everyone working in a unit (and the units below it) on a
 * day. Works without a tenant context (jobs, other modules): every lookup
 * names the company and stays inside it.
 */
class EmployeeDirectory
{
    public function find(Organization $company, string $id): ?EmployeeRecord
    {
        $employee = $this->query($company)->whereKey($id)->first();

        return $employee === null ? null : $this->record($employee);
    }

    /** The employee a login belongs to in this company, if HR linked one. */
    public function forUser(Organization $company, User $user): ?EmployeeRecord
    {
        $employee = $this->query($company)->where('user_id', $user->getKey())
            ->where('status', '!=', EmployeeStatus::Exited->value)->first();

        return $employee === null ? null : $this->record($employee);
    }

    /**
     * Employees working in a unit or below it (employed on $on when given), by name.
     *
     * @param  list<string>  $unitIds  The unit and its sub-units.
     * @return list<EmployeeRecord>
     */
    public function inUnits(Organization $company, array $unitIds, ?CarbonImmutable $on = null, ?int $limit = null): array
    {
        $employees = $this->query($company)->whereIn('organization_id', $unitIds)
            ->when($on !== null, fn (Builder $query) => $query->where('joined_on', '<=', $on->toDateString())
                ->where(fn (Builder $inner) => $inner->whereNull('exits_on')->orWhere('exits_on', '>=', $on->toDateString())))
            ->orderBy('full_name')->orderBy('id')
            ->when($limit !== null, fn (Builder $query) => $query->limit($limit))
            ->get();

        return $employees->map(fn (Employee $employee) => $this->record($employee))->values()->all();
    }

    /**
     * Several employees by id (unknown ids are left out).
     *
     * @param  list<string>  $ids
     * @return array<string, EmployeeRecord>
     */
    public function many(Organization $company, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->query($company)->whereKey(array_values(array_unique($ids)))->get()
            ->mapWithKeys(fn (Employee $employee) => [$employee->getKey() => $this->record($employee)])->all();
    }

    /**
     * @return Builder<Employee>
     */
    private function query(Organization $company): Builder
    {
        return Employee::inTenantOf($company)->withoutGlobalScope(OrganizationScope::class)->where('company_id', $company->getKey());
    }

    private function record(Employee $employee): EmployeeRecord
    {
        return new EmployeeRecord(
            id: $employee->getKey(),
            companyId: (string) $employee->company_id,
            unitId: (string) $employee->organization_id,
            code: (string) $employee->employee_code,
            name: (string) $employee->full_name,
            status: $employee->status->value,
            userId: $employee->user_id,
            joinedOn: $employee->joined_on,
            exitsOn: $employee->exits_on,
        );
    }
}
