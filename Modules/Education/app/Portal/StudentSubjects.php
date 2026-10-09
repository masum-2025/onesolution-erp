<?php

namespace Modules\Education\Portal;

use App\Platform\Portal\Contracts\PortalSubjectProvider;
use App\Platform\Portal\PortalSubject;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Modules\Education\Models\Enrollment;
use Modules\Education\Models\Level;
use Modules\Education\Models\Program;
use Modules\Education\Models\Section;
use Modules\Education\Models\Student;
use Modules\Education\Services\Fields;

/**
 * A student in the client's portal: their guardians ("guardian") or the
 * student themself ("self", university students) see name, code, program,
 * current level, section and roll, status, and the own fields marked for the
 * portal. Never the date of birth, registration number, sensitive fields or
 * ids of anything else. Lookups stay inside the institution, in the client's
 * own database, without a tenant context (joining).
 */
class StudentSubjects implements PortalSubjectProvider
{
    public function key(): string
    {
        return 'education.student';
    }

    public function label(?string $locale = null): string
    {
        return __('education::education.portal.subject', [], $locale);
    }

    public function relations(): array
    {
        return ['guardian', 'self'];
    }

    public function find(Organization $organization, string $id): ?PortalSubject
    {
        $student = $this->query($organization)->whereKey($id)->first();

        return $student === null ? null : $this->subject($student);
    }

    public function search(Organization $organization, string $term, int $limit = 20): array
    {
        return $this->query($organization)
            ->when($term !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('name', 'like', '%'.$term.'%')->orWhere('code', 'like', '%'.$term.'%')))
            ->whereIn('status', ['active', 'suspended'])
            ->orderBy('name')->limit($limit)->get()
            ->map(fn (Student $student) => $this->subject($student))
            ->all();
    }

    public function details(PortalSubject $subject, ?string $locale = null): array
    {
        /** @var Student $student */
        $student = Student::inTenantOf($subject->organizationId)->withoutGlobalScope(OrganizationScope::class)->findOrFail($subject->id);
        $company = Organization::query()->findOrFail($student->organization_id);
        $in = fn (string $model, ?string $id) => $id === null ? null : $model::inTenantOf($company)->withoutGlobalScope(OrganizationScope::class)->where('organization_id', $company->getKey())->whereKey($id)->first();
        $enrollment = Enrollment::inTenantOf($company)->withoutGlobalScope(OrganizationScope::class)->where('organization_id', $company->getKey())
            ->where('student_id', $student->getKey())->where('status', 'active')->first();
        $label = fn (string $key) => __('education::education.portal.'.$key, [], $locale);

        $details = [
            'name' => ['label' => $label('name'), 'value' => $student->name],
            'code' => ['label' => $label('code'), 'value' => $student->code],
            'program' => ['label' => $label('program'), 'value' => $in(Program::class, $student->program_id)?->textIn('name', $locale)],
            'level' => ['label' => $label('level'), 'value' => $enrollment === null ? null : $in(Level::class, $enrollment->level_id)?->textIn('name', $locale)],
            'section' => ['label' => $label('section'), 'value' => $enrollment === null ? null : $in(Section::class, $enrollment->section_id)?->name],
            'roll_no' => ['label' => $label('roll_no'), 'value' => $enrollment?->roll_no],
            'status' => ['label' => $label('status'), 'value' => __('education::education.statuses.'.$student->status, [], $locale)],
        ];

        $fields = app(Fields::class);
        $shown = $fields->visible($company, 'student', $student->extra, sensitive: false, portal: true);
        foreach ($fields->of($company, 'student') as $field) {
            if (array_key_exists($field->key, $shown)) {
                $value = $shown[$field->key];
                $details['extra.'.$field->key] = ['label' => $field->textIn('label', $locale), 'value' => is_array($value) ? implode(', ', $value) : $value];
            }
        }

        return $details;
    }

    /** The organization and the units below it, in the client's own database. */
    private function query(Organization $organization)
    {
        $ids = Organization::query()->subtreeOf($organization)->pluck('id')->all();

        // A student's campus is inside the institution, so the campus alone keeps others out.
        return Student::inTenantOf($organization)->withoutGlobalScope(OrganizationScope::class)->whereIn('unit_id', $ids);
    }

    private function subject(Student $student): PortalSubject
    {
        return new PortalSubject('education.student', $student->getKey(), $student->unit_id, $student->name);
    }
}
