<?php

namespace Modules\Hrm\Export;

use App\Platform\DataExport\Contracts\ExportsModuleData;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Modules\Hrm\Models\CustomField;
use Modules\Hrm\Models\Employee;
use Modules\Hrm\Models\EmployeeDocument;
use Modules\Hrm\Models\EmploymentEvent;
use Modules\Hrm\Models\Position;

/**
 * HRM's part of the client's data export (the client owns its data, so ids
 * are included in full). Documents are listed, their files are not copied.
 * Runs in the export job without a tenant context: reads the client's own
 * database for exactly the organizations given.
 */
class HrmExporter implements ExportsModuleData
{
    public function moduleKey(): string
    {
        return 'hrm';
    }

    public function export(Organization $organization, array $organizationIds): array
    {
        return [
            'positions' => $this->rows(Position::class, $organization, $organizationIds, fn (Position $position) => [
                'id' => $position->getKey(),
                'organization_id' => $position->organization_id,
                'code' => $position->code,
                'title' => json_encode($position->texts('title'), JSON_UNESCAPED_UNICODE),
                'grade' => $position->grade,
                'is_active' => $position->is_active,
            ]),
            'employees' => $this->rows(Employee::class, $organization, $organizationIds, fn (Employee $employee) => [
                'id' => $employee->getKey(),
                'organization_id' => $employee->organization_id,
                'employee_code' => $employee->employee_code,
                'full_name' => $employee->full_name,
                'full_name_local' => $employee->full_name_local,
                'date_of_birth' => $employee->date_of_birth?->toDateString(),
                'gender' => $employee->gender,
                'phone' => $employee->phone,
                'email' => $employee->email,
                'address' => $employee->address === null ? null : json_encode($employee->address, JSON_UNESCAPED_UNICODE),
                'emergency_contact' => $employee->emergency_contact === null ? null : json_encode($employee->emergency_contact, JSON_UNESCAPED_UNICODE),
                'custom' => $employee->custom === null ? null : json_encode($employee->custom, JSON_UNESCAPED_UNICODE),
                'national_id' => $employee->national_id,
                'tax_id' => $employee->tax_id,
                'employment_type' => $employee->employment_type,
                'position_id' => $employee->position_id,
                'manager_id' => $employee->manager_id,
                'status' => $employee->status->value,
                'joined_on' => $employee->joined_on->toDateString(),
                'confirmed_on' => $employee->confirmed_on?->toDateString(),
                'exits_on' => $employee->exits_on?->toDateString(),
                'exit_reason' => $employee->exit_reason,
            ]),
            'custom_fields' => $this->rows(CustomField::class, $organization, $organizationIds, fn (CustomField $field) => [
                'id' => $field->getKey(),
                'organization_id' => $field->organization_id,
                'key' => $field->key,
                'label' => json_encode($field->texts('label'), JSON_UNESCAPED_UNICODE),
                'type' => $field->type->value,
                'options' => $field->options === null ? null : json_encode($field->options, JSON_UNESCAPED_UNICODE),
                'is_required' => $field->is_required,
                'is_active' => $field->is_active,
            ]),
            'employment_events' => $this->rows(EmploymentEvent::class, $organization, $organizationIds, fn (EmploymentEvent $event) => [
                'id' => $event->getKey(),
                'employee_id' => $event->employee_id,
                'type' => $event->type->value,
                'effective_on' => $event->effective_on->toDateString(),
                'from' => $event->from === null ? null : json_encode($event->from),
                'to' => $event->to === null ? null : json_encode($event->to),
                'reason' => $event->reason,
                'created_at' => $event->created_at?->toIso8601String(),
            ]),
            'documents' => $this->rows(EmployeeDocument::class, $organization, $organizationIds, fn (EmployeeDocument $document) => [
                'id' => $document->getKey(),
                'employee_id' => $document->employee_id,
                'type' => $document->type,
                'title' => $document->title,
                'mime' => $document->mime,
                'size_bytes' => $document->size_bytes,
                'expires_on' => $document->expires_on?->toDateString(),
            ]),
        ];
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @param  list<string>  $organizationIds
     * @param  callable(T): array<string, scalar|null>  $row
     * @return iterable<array<string, scalar|null>>
     */
    private function rows(string $model, Organization $organization, array $organizationIds, callable $row): iterable
    {
        foreach (array_chunk($organizationIds, 500) as $chunk) {
            foreach ($model::inTenantOf($organization)->withoutGlobalScope(OrganizationScope::class)->whereIn('organization_id', $chunk)->orderBy('id')->lazy(500) as $record) {
                yield $row($record);
            }
        }
    }
}
