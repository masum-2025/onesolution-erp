<?php

namespace Modules\Education\Services;

use App\Models\User;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Modules\Hrm\Directory\EmployeeDirectory;

/**
 * Teachers are HRM employees, read through HRM's public directory (never
 * its tables). With HRM off or missing there are no teachers to pick and
 * nobody is anyone's class teacher.
 */
class Teachers
{
    public function __construct(private ModuleResolver $modules) {}

    public function available(Organization $company): bool
    {
        return class_exists(EmployeeDirectory::class) && $this->modules->isEnabled('hrm', $company);
    }

    public function exists(Organization $company, string $employeeId): bool
    {
        return $this->available($company) && app(EmployeeDirectory::class)->find($company, $employeeId) !== null;
    }

    /** The employee a login belongs to here, if HR linked one. */
    public function employeeOf(Organization $company, User $user): ?string
    {
        return $this->available($company) ? app(EmployeeDirectory::class)->forUser($company, $user)?->id : null;
    }

    /**
     * Names by employee id (unknown ids left out).
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public function names(Organization $company, array $ids): array
    {
        if (! $this->available($company) || $ids === []) {
            return [];
        }

        return array_map(fn ($record) => $record->name, app(EmployeeDirectory::class)->many($company, $ids));
    }

    /**
     * Teachers to pick for a campus and the units below it.
     *
     * @param  list<string>  $unitIds
     * @return list<array{id: string, name: string, code: string}>
     */
    public function in(Organization $company, array $unitIds): array
    {
        if (! $this->available($company)) {
            return [];
        }

        return array_map(fn ($record) => ['id' => $record->id, 'name' => $record->name, 'code' => $record->code], app(EmployeeDirectory::class)->inUnits($company, $unitIds, now()->toImmutable(), 500));
    }
}
