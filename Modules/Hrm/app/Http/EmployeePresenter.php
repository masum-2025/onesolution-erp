<?php

namespace Modules\Hrm\Http;

use App\Platform\Tenancy\Models\Organization;
use Modules\Hrm\Models\Employee;
use Modules\Hrm\Models\EmployeeDocument;
use Modules\Hrm\Models\EmploymentEvent;
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
            'national_id' => self::mask($employee->national_id),
            'tax_id' => self::mask($employee->tax_id),
            'manager' => $employee->manager === null ? null : ['id' => $employee->manager->getKey(), 'full_name' => $employee->manager->full_name],
            'probation_ends_on' => $employee->probation_ends_on?->toDateString(),
            'confirmed_on' => $employee->confirmed_on?->toDateString(),
            'notice_given_on' => $employee->notice_given_on?->toDateString(),
            'exits_on' => $employee->exits_on?->toDateString(),
            'exit_reason' => $employee->exit_reason,
            'user_id' => $employee->user_id,
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
