<?php

namespace Modules\Hrm\Letters;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Modules\Hrm\Models\Employee;
use Modules\Letters\Contracts\LetterFieldSource;

/**
 * The employee in letters (Letters' LetterFieldSource): name, code,
 * designation, department, kind of employment, dates of joining,
 * probation, confirmation and leaving, address and length of service.
 * Never national or tax ids, phone or e-mail; nothing where HRM is off.
 */
class EmployeeLetterFields implements LetterFieldSource
{
    private const FIELDS = [
        'name', 'name_local', 'code', 'designation', 'department', 'employment_type',
        'joined_on', 'probation_ends_on', 'confirmed_on', 'exits_on', 'address', 'service_length',
    ];

    public function __construct(private ModuleResolver $modules) {}

    public function fields(Organization $company, string $locale): array
    {
        if (! $this->modules->isEnabled('hrm', $company)) {
            return [];
        }

        return [[
            'group' => 'employee',
            'label' => __('hrm::letters.group', [], $locale),
            'fields' => array_map(fn (string $name) => [
                'key' => "employee.{$name}",
                'label' => __("hrm::letters.{$name}", [], $locale),
                'salary' => false,
                'sample' => __("hrm::letters.sample_{$name}", [], $locale),
            ], self::FIELDS),
        ]];
    }

    public function values(Organization $company, array $employeeIds, string $locale): array
    {
        if ($employeeIds === [] || ! $this->modules->isEnabled('hrm', $company)) {
            return [];
        }
        $employees = Employee::inTenantOf($company)->withoutGlobalScope(OrganizationScope::class)
            ->where('company_id', $company->getKey())->whereKey(array_values(array_unique($employeeIds)))->with('position')->get();
        $units = Organization::query()->whereKey($employees->pluck('organization_id')->unique()->all())->get()->keyBy('id');
        $date = fn (?CarbonImmutable $day) => $day?->locale($locale)->isoFormat('LL');

        $values = [];
        foreach ($employees as $employee) {
            $unit = $units->get($employee->organization_id);
            $values[$employee->getKey()] = array_filter([
                'employee.name' => (string) $employee->full_name,
                'employee.name_local' => $employee->full_name_local,
                'employee.code' => (string) $employee->employee_code,
                'employee.designation' => $employee->position?->textIn('title', $locale),
                'employee.department' => $unit !== null && $unit->getKey() !== $company->getKey() ? $unit->displayName($locale) : null,
                'employee.employment_type' => $employee->employment_type ? __('hrm::rules.employment_types.options.'.$employee->employment_type, [], $locale) : null,
                'employee.joined_on' => $date($employee->joined_on),
                'employee.probation_ends_on' => $date($employee->probation_ends_on),
                'employee.confirmed_on' => $date($employee->confirmed_on),
                'employee.exits_on' => $date($employee->exits_on),
                'employee.address' => implode(', ', array_filter(array_map(fn ($part) => is_string($part) ? trim($part) : null, (array) $employee->address))) ?: null,
                'employee.service_length' => $employee->joined_on?->locale($locale)
                    ->diffForHumans(($employee->exits_on ?? CarbonImmutable::now('UTC')), CarbonInterface::DIFF_ABSOLUTE, false, 2),
            ], fn ($value) => is_string($value) && $value !== '');
        }

        return $values;
    }
}
