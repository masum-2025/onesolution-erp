<?php

namespace Modules\Education\Services;

use App\Models\User;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Education\Models\Enrollment;
use Modules\Education\Models\Section;

/**
 * Which students someone may see at a unit. People who admit, edit or set
 * up see every student of the unit and the units below it. A teacher (only
 * education.view) sees, with the rule education.teacher_scope at
 * "own_sections", only the students of the sections they are class teacher
 * of (their login linked to an HRM employee); with "all", every student.
 */
class Access
{
    public function __construct(
        private Education $education,
        private Teachers $teachers,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /**
     * null: every student of the unit; otherwise only those in these sections.
     *
     * @return list<string>|null
     */
    public function sections(Organization $company, Organization $unit, User $user): ?array
    {
        foreach (['education.manage', 'education.admit', 'education.edit_students'] as $permission) {
            if (Gate::forUser($user)->allows($permission, $unit)) {
                return null;
            }
        }
        if ((string) $this->rules->get('education.teacher_scope', $this->contexts->forOrganization($unit)) === 'all') {
            return null;
        }

        $employee = $this->teachers->employeeOf($company, $user);
        if ($employee === null) {
            return [];
        }

        return $this->education->query(Section::class, $company)->where('class_teacher_id', $employee)
            ->whereIn('unit_id', $this->education->subtreeIds($unit))->pluck('id')->all();
    }

    /**
     * Narrow a student query to what the person may see.
     *
     * @param  list<string>|null  $sections
     */
    public function restrict(Builder $students, Organization $company, ?array $sections): Builder
    {
        if ($sections === null) {
            return $students;
        }

        $ids = $this->education->query(Enrollment::class, $company)->whereIn('section_id', $sections)->where('status', 'active')->pluck('student_id')->all();

        return $students->whereIn('id', $ids);
    }

    public function canSeeStudent(Organization $company, ?array $sections, string $studentId): bool
    {
        return $sections === null
            || $this->education->query(Enrollment::class, $company)->where('student_id', $studentId)->whereIn('section_id', $sections)->where('status', 'active')->exists();
    }
}
