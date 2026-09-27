<?php

namespace Tests\Fixtures;

use App\Platform\Portal\Contracts\PortalSubjectProvider;
use App\Platform\Portal\PortalSubject;
use App\Platform\Tenancy\Models\Organization;

/**
 * How a school module would let a portal show its students: parents
 * (guardians) see their own children. Test-only (Phase 5C-4).
 */
class FixtureStudentProvider implements PortalSubjectProvider
{
    public function key(): string
    {
        return 'school.student';
    }

    public function label(?string $locale = null): string
    {
        return 'Student';
    }

    public function relations(): array
    {
        return ['guardian'];
    }

    public function find(Organization $organization, string $id): ?PortalSubject
    {
        $student = $this->query($organization)->whereKey($id)->first();

        return $student === null ? null : $this->subject($student);
    }

    public function search(Organization $organization, string $term, int $limit = 20): array
    {
        return $this->query($organization)
            ->when($term !== '', fn ($query) => $query->where('name', 'like', '%'.$term.'%'))
            ->orderBy('name')->limit($limit)->get()
            ->map(fn (FixtureStudent $student) => $this->subject($student))
            ->all();
    }

    public function details(PortalSubject $subject, ?string $locale = null): array
    {
        $student = FixtureStudent::query()->withoutGlobalScopes()->findOrFail($subject->id);

        return [
            'name' => ['label' => 'Name', 'value' => $student->name],
            'class_name' => ['label' => 'Class', 'value' => $student->class_name],
            'date_of_birth' => ['label' => 'Date of birth', 'value' => $student->date_of_birth?->toDateString()],
        ];
    }

    /** The organization and the units below it; no tenant context needed. */
    private function query(Organization $organization)
    {
        return FixtureStudent::query()->withoutGlobalScopes()
            ->whereIn('organization_id', Organization::query()->where('path', 'like', $organization->path.'%')->select('id'));
    }

    private function subject(FixtureStudent $student): PortalSubject
    {
        return new PortalSubject('school.student', $student->getKey(), $student->organization_id, $student->name);
    }
}
