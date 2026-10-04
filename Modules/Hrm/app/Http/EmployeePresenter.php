<?php

namespace Modules\Hrm\Http;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Modules\Hrm\Models\CustomField;
use Modules\Hrm\Models\Employee;
use Modules\Hrm\Models\EmployeeDocument;
use Modules\Hrm\Models\EmployeeImport;
use Modules\Hrm\Models\EmploymentEvent;
use Modules\Hrm\Models\ImportRow;
use Modules\Hrm\Models\Position;

/**
 * API shapes of HRM records. National and tax ids are always masked here;
 * the full values come only from the audited "sensitive" endpoint.
 */
class EmployeePresenter
{
    /** @var array<string, string> */
    private array $unitNames = [];

    /**
     * @return array<string, mixed>
     */
    public function listItem(Employee $employee): array
    {
        return [
            'id' => $employee->getKey(),
            'employee_code' => $employee->employee_code,
            'full_name' => $employee->full_name,
            'full_name_local' => $employee->full_name_local,
            'unit' => ['id' => $employee->organization_id, 'name' => $this->unitName($employee->organization_id)],
            'position' => $employee->position === null ? null : ['id' => $employee->position->getKey(), 'title' => $employee->position->textIn('title')],
            'employment_type' => $employee->employment_type,
            'status' => $employee->status->value,
            'status_label' => __('hrm::hrm.statuses.'.$employee->status->value),
            'joined_on' => $employee->joined_on->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Employee $employee): array
    {
        return [
            ...$this->listItem($employee),
            'version' => $employee->version,
            'date_of_birth' => $employee->date_of_birth?->toDateString(),
            'gender' => $employee->gender,
            'phone' => $employee->phone,
            'email' => $employee->email,
            'address' => $employee->address,
            'emergency_contact' => $employee->emergency_contact,
            'custom' => (object) ($employee->custom ?? []),
            'national_id' => self::mask($employee->national_id),
            'tax_id' => self::mask($employee->tax_id),
            'manager' => $employee->manager === null ? null : ['id' => $employee->manager->getKey(), 'full_name' => $employee->manager->full_name],
            'probation_ends_on' => $employee->probation_ends_on?->toDateString(),
            'confirmed_on' => $employee->confirmed_on?->toDateString(),
            'notice_given_on' => $employee->notice_given_on?->toDateString(),
            'exits_on' => $employee->exits_on?->toDateString(),
            'exit_reason' => $employee->exit_reason,
            'user_id' => $employee->user_id,
            // The login linked to the employee (they check in and see their own records with it).
            'login' => $employee->user_id === null ? null : User::query()->whereKey($employee->user_id)->first(['id', 'name', 'email'])?->only(['id', 'name', 'email']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function event(EmploymentEvent $event): array
    {
        return [
            'id' => $event->getKey(),
            'type' => $event->type->value,
            'label' => __('hrm::hrm.events.'.$event->type->value),
            'effective_on' => $event->effective_on->toDateString(),
            'from' => $event->from,
            'to' => $event->to,
            'reason' => $event->reason,
            'actor_user_id' => $event->actor_user_id,
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function document(EmployeeDocument $document): array
    {
        return [
            'id' => $document->getKey(),
            'type' => $document->type,
            'title' => $document->title,
            'mime' => $document->mime,
            'size_bytes' => $document->size_bytes,
            'expires_on' => $document->expires_on?->toDateString(),
            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function position(Position $position): array
    {
        return [
            'id' => $position->getKey(),
            'organization_id' => $position->organization_id,
            'code' => $position->code,
            'title' => $position->textIn('title'),
            'titles' => $position->texts('title'),
            'grade' => $position->grade,
            'is_active' => $position->is_active,
            'version' => $position->version,
        ];
    }

    /**
     * An extra field as seen from $unit: "self" when set up there, else
     * "inherited" with the unit it comes from (shown next to it).
     *
     * @return array<string, mixed>
     */
    public function customField(CustomField $field, ?Organization $unit = null): array
    {
        $locale = app()->getLocale();

        return [
            'id' => $field->getKey(),
            'organization_id' => $field->organization_id,
            'source' => [
                'kind' => $unit === null || $field->organization_id === $unit->getKey() ? 'self' : 'inherited',
                'unit' => $this->unitName($field->organization_id),
            ],
            'key' => $field->key,
            'label' => $field->textIn('label'),
            'labels' => $field->texts('label'),
            'type' => $field->type->value,
            'options' => array_map(fn (array $option) => [
                'value' => $option['value'],
                'label' => $option['label'][$locale] ?? $option['label']['en'] ?? $option['value'],
                'labels' => $option['label'],
            ], $field->options ?? []),
            'is_required' => $field->is_required,
            'sort_order' => $field->sort_order,
            'is_active' => $field->is_active,
            'version' => $field->version,
        ];
    }

    /**
     * An import and, with rows, the lines that need attention (problems
     * named per column; the name only while the details are still kept).
     *
     * @return array<string, mixed>
     */
    public function import(EmployeeImport $import, bool $withRows = false): array
    {
        $shape = [
            'id' => $import->getKey(),
            'unit' => ['id' => $import->organization_id, 'name' => $this->unitName($import->organization_id)],
            'file_name' => $import->file_name,
            'status' => $import->status->value,
            'status_label' => __('hrm::hrm.import_statuses.'.$import->status->value),
            'total_rows' => $import->total_rows,
            'valid_rows' => $import->total_rows - $import->invalid_rows,
            'invalid_rows' => $import->invalid_rows,
            'imported_rows' => $import->imported_rows,
            'failed_rows' => $import->failed_rows,
            'created_at' => $import->created_at?->toIso8601String(),
            'finished_at' => $import->finished_at?->toIso8601String(),
        ];

        if ($withRows) {
            $shape['progress'] = $import->rows()->whereIn('status', ['imported', 'failed'])->count();
            $shape['problems'] = $import->rows()->whereNotNull('errors')->orderBy('row_no')->limit(500)->get()
                ->map(fn (ImportRow $row) => [
                    'row_no' => $row->row_no,
                    'name' => $row->data['full_name'] ?? null,
                    'status' => $row->status->value,
                    'errors' => $row->errors,
                ])->values();
        }

        return $shape;
    }

    /** "••••1234": enough to recognize, not enough to use. */
    public static function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return str_repeat('•', 4).mb_substr($value, -4);
    }

    private function unitName(string $id): string
    {
        return $this->unitNames[$id] ??= (string) Organization::query()->find($id)?->displayName();
    }
}
