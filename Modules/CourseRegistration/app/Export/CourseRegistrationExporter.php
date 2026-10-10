<?php

namespace Modules\CourseRegistration\Export;

use App\Platform\DataExport\Contracts\ExportsModuleData;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Modules\CourseRegistration\Models\Offering;
use Modules\CourseRegistration\Models\Registration;
use Modules\CourseRegistration\Models\RegistrationItem;
use Modules\CourseRegistration\Models\Window;

/**
 * Course registration in the client's data export: windows, offerings,
 * registrations and every registration item with its outcome.
 */
class CourseRegistrationExporter implements ExportsModuleData
{
    public function moduleKey(): string
    {
        return 'course_registration';
    }

    public function export(Organization $organization, array $organizationIds): array
    {
        $plain = fn (Model $row, array $columns) => array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value, $row->only(['id', ...$columns]));

        return [
            'windows' => $this->rows(Window::class, $organization, $organizationIds, fn ($row) => $plain($row, ['session_id', 'opens_on', 'closes_on', 'add_drop_until'])),
            'offerings' => $this->rows(Offering::class, $organization, $organizationIds, fn ($row) => $plain($row, ['unit_id', 'session_id', 'level_id', 'subject_id', 'group_name', 'kind', 'teacher_id', 'capacity', 'credits_centi', 'status', 'note'])),
            'registrations' => $this->rows(Registration::class, $organization, $organizationIds, fn ($row) => $plain($row, ['unit_id', 'session_id', 'student_id', 'status', 'credits_centi', 'overload', 'submitted_at', 'approved_at', 'note'])),
            'registration_items' => $this->rows(RegistrationItem::class, $organization, $organizationIds, fn ($row) => $plain($row, ['registration_id', 'student_id', 'session_id', 'offering_id', 'subject_id', 'credits_centi', 'status', 'source', 'waitlisted_at', 'registered_at', 'ended_at', 'reason', 'outcome', 'outcome_at'])),
        ];
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $organizationIds
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
