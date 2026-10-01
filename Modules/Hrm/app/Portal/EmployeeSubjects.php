<?php

namespace Modules\Hrm\Portal;

use App\Platform\Portal\Contracts\PortalSubjectProvider;
use App\Platform\Portal\PortalSubject;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Modules\Hrm\Models\Employee;

/**
 * An employee sees their own record in the client's portal ("self"): name,
 * code, position, unit, joining date, status, contact. Never ids or
 * documents. Lookups stay inside the given organization and its units, in
 * the client's own database, without a tenant context (joining).
 */
class EmployeeSubjects implements PortalSubjectProvider
{
    public function key(): string
    {
        return 'hrm.employee';
    }

    public function label(?string $locale = null): string
    {
        return __('hrm::hrm.portal.subject', [], $locale);
    }

    public function relations(): array
    {
        return ['self'];
    }

    public function find(Organization $organization, string $id): ?PortalSubject
    {
        $employee = $this->query($organization)->whereKey($id)->first();

        return $employee === null ? null : $this->subject($employee);
    }

    public function search(Organization $organization, string $term, int $limit = 20): array
    {
        return $this->query($organization)
            ->when($term !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('full_name', 'like', '%'.$term.'%')->orWhere('employee_code', 'like', '%'.$term.'%')))
            ->orderBy('full_name')->limit($limit)->get()
            ->map(fn (Employee $employee) => $this->subject($employee))
            ->all();
    }

    public function details(PortalSubject $subject, ?string $locale = null): array
    {
        /** @var Employee $employee */
        $employee = Employee::inTenantOf($subject->organizationId)->withoutGlobalScope(OrganizationScope::class)->with('position')->findOrFail($subject->id);
        $label = fn (string $key) => __('hrm::hrm.portal.'.$key, [], $locale);

        return [
            'full_name' => ['label' => $label('full_name'), 'value' => $employee->full_name],
            'employee_code' => ['label' => $label('employee_code'), 'value' => $employee->employee_code],
            'position' => ['label' => $label('position'), 'value' => $employee->position?->textIn('title', $locale)],
            'unit' => ['label' => $label('unit'), 'value' => Organization::query()->find($employee->organization_id)?->displayName()],
            'joined_on' => ['label' => $label('joined_on'), 'value' => $employee->joined_on->toDateString()],
            'status' => ['label' => $label('status'), 'value' => __('hrm::hrm.statuses.'.$employee->status->value, [], $locale)],
            'phone' => ['label' => $label('phone'), 'value' => $employee->phone],
            'email' => ['label' => $label('email'), 'value' => $employee->email],
        ];
    }

    /** The organization and the units below it, in the client's own database. */
    private function query(Organization $organization)
    {
        $ids = Organization::query()->subtreeOf($organization)->pluck('id')->all();

        return Employee::inTenantOf($organization)->withoutGlobalScope(OrganizationScope::class)->whereIn('organization_id', $ids);
    }

    private function subject(Employee $employee): PortalSubject
    {
        return new PortalSubject('hrm.employee', $employee->getKey(), $employee->organization_id, $employee->full_name);
    }
}
