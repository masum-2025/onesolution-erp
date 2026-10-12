<?php

namespace Modules\EducationExams\Services;

use App\Models\User;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationExams\Models\MarkSheet;
use Modules\EducationExams\Models\Paper;

/**
 * Whose marks and results a person may see at a unit.
 *
 * - Exam office, heads and moderators (education_exams.manage, .publish,
 *   .moderate, .correct) and people who run students (education.manage,
 *   .admit, .edit_students) see everything at the unit and below.
 * - A teacher (their login linked to an HRM employee) sees the sections
 *   they teach: the whole result of a section they are class teacher of
 *   when rule education_exams.class_teacher_sees_section_results, of a
 *   section they enter a sheet in when rule
 *   education_exams.subject_teacher_sees_section_results; otherwise only
 *   the subjects they enter there (marks and grades, no GPA or position).
 * - Anyone else sees no student's marks.
 */
class ResultAccess
{
    private const SEES_ALL = [
        'education_exams.manage', 'education_exams.publish', 'education_exams.moderate', 'education_exams.correct',
        'education.manage', 'education.admit', 'education.edit_students',
    ];

    public function __construct(
        private ExamOffice $office,
        private AcademicDirectory $academic,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /**
     * null: everything; otherwise section id => true (the whole result) or
     * the subject ids that may be seen there. A section not listed: nothing.
     *
     * @return array<string, true|list<string>>|null
     */
    public function scope(Organization $company, Organization $unit, User $user): ?array
    {
        foreach (self::SEES_ALL as $permission) {
            if (Gate::forUser($user)->allows($permission, $unit)) {
                return null;
            }
        }
        $employee = $this->academic->employeeOf($company, $user);
        if ($employee === null) {
            return [];
        }
        $units = $this->office->subtreeIds($unit);
        $context = $this->contexts->forOrganization($unit);
        $classWhole = (bool) $this->rules->get('education_exams.class_teacher_sees_section_results', $context);
        $subjectWhole = (bool) $this->rules->get('education_exams.subject_teacher_sees_section_results', $context);

        $scope = [];
        $classSections = $this->academic->classTeacherSections($company, $employee, $units);
        if ($classWhole) {
            foreach ($classSections as $section) {
                $scope[$section] = true;
            }
        }
        // The sheets they enter: named on them, or class teacher of a sheet that names nobody.
        $sheets = $this->office->query(MarkSheet::class, $company)->whereIn('unit_id', $units)
            ->where(fn ($query) => $query->where('teacher_id', $employee)
                ->orWhere(fn ($query) => $query->whereNull('teacher_id')->whereIn('section_id', $classSections)))
            ->get(['section_id', 'paper_id']);
        $subjects = $this->office->query(Paper::class, $company)->whereKey($sheets->pluck('paper_id')->unique()->values())->pluck('subject_id', 'id');
        foreach ($sheets as $sheet) {
            if (($scope[$sheet->section_id] ?? null) === true) {
                continue;
            }
            if ($subjectWhole) {
                $scope[$sheet->section_id] = true;

                continue;
            }
            $subject = (string) ($subjects[$sheet->paper_id] ?? '');
            if ($subject !== '' && ! in_array($subject, $scope[$sheet->section_id] ?? [], true)) {
                $scope[$sheet->section_id] = [...($scope[$sheet->section_id] ?? []), $subject];
            }
        }

        return $scope;
    }

    /**
     * Whether a subject of a section may be seen.
     *
     * @param  array<string, true|list<string>>|null  $scope
     */
    public function seesSubject(?array $scope, ?string $sectionId, string $subjectId): bool
    {
        if ($scope === null) {
            return true;
        }
        $allowed = $scope[$sectionId ?? ''] ?? [];

        return $allowed === true || in_array($subjectId, $allowed, true);
    }

    /**
     * Whether a section's whole result (GPA, position, every subject) may be seen.
     *
     * @param  array<string, true|list<string>>|null  $scope
     */
    public function seesWhole(?array $scope, ?string $sectionId): bool
    {
        return $scope === null || ($scope[$sectionId ?? ''] ?? null) === true;
    }

    /**
     * Sections whose whole result may be seen (null: all).
     *
     * @param  array<string, true|list<string>>|null  $scope
     * @return list<string>|null
     */
    public function wholeSections(?array $scope): ?array
    {
        return $scope === null ? null : array_keys(array_filter($scope, fn ($allowed) => $allowed === true));
    }
}
