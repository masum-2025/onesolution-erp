<?php

namespace Modules\Education\Setup;

use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Number;
use Modules\Education\Contracts\SetupStepProvider;
use Modules\Education\Models\Curriculum;
use Modules\Education\Models\Enrollment;
use Modules\Education\Models\Level;
use Modules\Education\Models\Program;
use Modules\Education\Models\Section;
use Modules\Education\Models\Subject;
use Modules\Education\Services\Education;
use Modules\Education\Services\Teachers;

/**
 * Education's own setup: what it is (programs, classes, subjects), when
 * (a session open now), what is taught (a curriculum for every program),
 * where (sections this session), who teaches the sections (with HRM, a
 * help rather than a need), and the students in them.
 */
class EducationSetupSteps implements SetupStepProvider
{
    public function __construct(private Education $education, private Teachers $teachers) {}

    public function module(): string
    {
        return 'education';
    }

    public function steps(Organization $company, ?string $sessionId, array $unitIds): array
    {
        $query = fn (string $model) => $this->education->query($model, $company);
        $programs = $query(Program::class)->where('is_active', true)->pluck('id');
        $levels = $query(Level::class)->where('is_active', true)->count();
        $subjects = $query(Subject::class)->where('is_active', true)->count();
        $withCurriculum = $query(Curriculum::class)->where('status', 'active')->whereIn('program_id', $programs)->distinct()->pluck('program_id');
        $noCurriculum = $programs->diff($withCurriculum)->count();
        $sections = $sessionId === null ? collect() : $query(Section::class)->where('session_id', $sessionId)->whereIn('unit_id', $unitIds)->where('is_active', true)->get(['id', 'class_teacher_id']);
        $students = $sessionId === null ? 0 : $query(Enrollment::class)->where('session_id', $sessionId)->whereIn('unit_id', $unitIds)->where('status', 'active')->count();
        // Numbers in the reader's own digits (১২ in Bangla).
        $text = fn (string $key, array $values = []) => __("education::setup.steps.{$key}", array_map(fn (int $value) => Number::format($value, locale: app()->getLocale()), $values));

        $steps = [
            [
                'key' => 'structure', 'label' => $text('structure.label'), 'route' => '/education/structure',
                'done' => $programs->isNotEmpty() && $levels > 0 && $subjects > 0,
                'detail' => $programs->isEmpty() ? $text('structure.no_programs') : ($subjects === 0 ? $text('structure.no_subjects') : null),
            ],
            [
                'key' => 'session', 'label' => $text('session.label'), 'route' => '/education/structure?tab=years',
                'done' => $sessionId !== null, 'detail' => $sessionId === null ? $text('session.missing') : null,
            ],
            [
                'key' => 'curriculum', 'label' => $text('curriculum.label'), 'route' => '/education/structure',
                'done' => $programs->isNotEmpty() && $noCurriculum === 0,
                'detail' => $programs->isNotEmpty() && $noCurriculum > 0 ? $text('curriculum.missing', ['count' => $noCurriculum]) : null,
            ],
            [
                'key' => 'sections', 'label' => $text('sections.label'), 'route' => '/education/sections',
                'done' => $sections->isNotEmpty(), 'detail' => $sections->isEmpty() ? $text('sections.missing') : null,
            ],
        ];
        if ($this->teachers->available($company)) {
            $without = $sections->whereNull('class_teacher_id')->count();
            $steps[] = [
                'key' => 'class_teachers', 'label' => $text('class_teachers.label'), 'route' => '/education/sections', 'optional' => true,
                'done' => $sections->isNotEmpty() && $without === 0,
                'detail' => $sections->isNotEmpty() && $without > 0 ? $text('class_teachers.missing', ['count' => $without]) : null,
            ];
        }
        $steps[] = [
            'key' => 'students', 'label' => $text('students.label'), 'route' => '/education/students',
            'done' => $students > 0, 'detail' => $students === 0 ? $text('students.missing') : null,
        ];

        return $steps;
    }
}
