<?php

namespace Modules\Education\Export;

use App\Platform\DataExport\Contracts\ExportsModuleData;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Modules\Education\Models\AcademicUnit;
use Modules\Education\Models\AcademicYear;
use Modules\Education\Models\Admission;
use Modules\Education\Models\Batch;
use Modules\Education\Models\Curriculum;
use Modules\Education\Models\CurriculumItem;
use Modules\Education\Models\Enrollment;
use Modules\Education\Models\Field;
use Modules\Education\Models\Guardian;
use Modules\Education\Models\Level;
use Modules\Education\Models\ListItem;
use Modules\Education\Models\Program;
use Modules\Education\Models\Section;
use Modules\Education\Models\Session;
use Modules\Education\Models\Student;
use Modules\Education\Models\StudentGuardian;
use Modules\Education\Models\Subject;

/**
 * Education in the client's data export: the structure, students (their
 * own data, sensitive details included: the client owns it), guardians and
 * their links, admissions, enrollments and own fields. Photos stay in files.
 */
class EducationExporter implements ExportsModuleData
{
    public function moduleKey(): string
    {
        return 'education';
    }

    public function export(Organization $organization, array $organizationIds): array
    {
        $json = fn ($value) => $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE);
        $plain = fn (Model $row, array $columns, array $texts = []) => [
            ...array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (is_array($value) ? $json($value) : $value), $row->only(['id', ...$columns])),
            ...array_map(fn (string $field) => $json($row->texts($field)), array_combine($texts, $texts) ?: []),
        ];

        return [
            'academic_units' => $this->rows(AcademicUnit::class, $organization, $organizationIds, fn ($row) => $plain($row, ['parent_id', 'kind', 'code', 'is_active'], ['name'])),
            'programs' => $this->rows(Program::class, $organization, $organizationIds, fn ($row) => $plain($row, ['academic_unit_id', 'code', 'progression', 'periods_per_year', 'total_credits_centi', 'is_active'], ['name', 'level_label', 'section_label'])),
            'levels' => $this->rows(Level::class, $organization, $organizationIds, fn ($row) => $plain($row, ['program_id', 'sequence', 'code', 'next_level_id', 'min_age', 'is_active'], ['name'])),
            'lists' => $this->rows(ListItem::class, $organization, $organizationIds, fn ($row) => $plain($row, ['kind', 'key', 'is_active'], ['name'])),
            'academic_years' => $this->rows(AcademicYear::class, $organization, $organizationIds, fn ($row) => $plain($row, ['name', 'starts_on', 'ends_on', 'status'])),
            'sessions' => $this->rows(Session::class, $organization, $organizationIds, fn ($row) => $plain($row, ['academic_year_id', 'kind', 'sequence', 'starts_on', 'ends_on', 'status'], ['name'])),
            'sections' => $this->rows(Section::class, $organization, $organizationIds, fn ($row) => $plain($row, ['unit_id', 'session_id', 'level_id', 'name', 'stream_id', 'shift_id', 'medium_id', 'capacity', 'class_teacher_id', 'is_active'])),
            'batches' => $this->rows(Batch::class, $organization, $organizationIds, fn ($row) => $plain($row, ['program_id', 'name', 'intake_session_id', 'curriculum_id', 'is_active'])),
            'subjects' => $this->rows(Subject::class, $organization, $organizationIds, fn ($row) => $plain($row, ['code', 'credits_centi', 'kind', 'is_active'], ['name'])),
            'curricula' => $this->rows(Curriculum::class, $organization, $organizationIds, fn ($row) => $plain($row, ['program_id', 'name', 'effective_from_year', 'status'])),
            'curriculum_items' => $this->rows(CurriculumItem::class, $organization, $organizationIds, fn ($row) => $plain($row, ['curriculum_id', 'level_id', 'subject_id', 'kind', 'stream_id'])),
            'students' => $this->rows(Student::class, $organization, $organizationIds, fn (Student $row) => [
                ...$plain($row, ['unit_id', 'code', 'admission_no', 'name', 'name_local', 'gender', 'date_of_birth', 'phone', 'email', 'program_id', 'batch_id', 'category_id', 'status', 'admitted_on', 'left_on', 'left_reason', 'extra']),
                'birth_registration_no' => $row->birth_registration_no,
            ]),
            'guardians' => $this->rows(Guardian::class, $organization, $organizationIds, fn (Guardian $row) => [
                ...$plain($row, ['name', 'phone', 'email', 'occupation', 'extra']),
                'national_id' => $row->national_id,
            ]),
            'student_guardians' => $this->rows(StudentGuardian::class, $organization, $organizationIds, fn ($row) => $plain($row, ['student_id', 'guardian_id', 'relation', 'is_primary', 'can_pick_up', 'receives_notices'])),
            'admissions' => $this->rows(Admission::class, $organization, $organizationIds, fn ($row) => $plain($row, ['unit_id', 'number', 'program_id', 'level_id', 'session_id', 'applicant', 'source', 'status', 'note', 'student_id'])),
            'enrollments' => $this->rows(Enrollment::class, $organization, $organizationIds, fn ($row) => $plain($row, ['unit_id', 'student_id', 'session_id', 'level_id', 'section_id', 'roll_no', 'status', 'started_on', 'ended_on'])),
            'fields' => $this->rows(Field::class, $organization, $organizationIds, fn ($row) => $plain($row, ['entity', 'key', 'type', 'options', 'is_required', 'portal_visible', 'on_documents', 'is_sensitive', 'is_active'], ['label'])),
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
