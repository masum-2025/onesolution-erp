<?php

namespace Modules\EducationExams\Services;

use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationExams\Contracts\StudentAttendance;
use Modules\EducationExams\Models\Combination;
use Modules\EducationExams\Models\Exam;
use Modules\EducationExams\Models\GradingScheme;
use Modules\EducationExams\Models\Mark;
use Modules\EducationExams\Models\PaperPart;
use Modules\EducationExams\Models\Result;

/**
 * What a printed report card (marksheet) or tabulation sheet shows, for
 * results of one exam or one combination: the institution, the exam, the
 * grading bands, who signs (rule education_exams.marksheet_signatures),
 * and for each student their roll, section, every subject (with its parts
 * for an exam), total, GPA, letter and position (rule show_rank), and the
 * days attended when a module keeps them (StudentAttendance).
 */
class ReportCards
{
    public function __construct(
        private ExamOffice $office,
        private Results $results,
        private AcademicDirectory $academic,
        private Grading $grading,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /**
     * @param  Collection<int, Result>  $rows  results of the source (already narrowed to what the reader may see)
     * @return array<string, mixed>
     */
    public function build(Organization $company, Organization $unit, Exam|Combination $source, Collection $rows): array
    {
        $context = $this->contexts->forOrganization($unit);
        $showRank = (bool) $this->rules->get('education_exams.show_rank', $context);
        $scheme = $this->office->query(GradingScheme::class, $company)->find($source->grading_scheme_id);
        $bands = $scheme === null ? [] : ($this->grading->bands($company, [$scheme->getKey()])[$scheme->getKey()] ?? []);
        $sessionId = $source->session_id;
        $studentIds = $rows->pluck('student_id')->unique()->values()->all();

        $subjectRows = $this->results->subjectsOf($company, $rows->pluck('id')->all())->groupBy('result_id');
        $subjects = $this->academic->subjects($company, $subjectRows->flatten()->pluck('subject_id')->unique()->values()->all());
        $students = $this->academic->students($company, $studentIds);
        $rolls = $this->academic->rollNumbers($company, $sessionId, $studentIds);
        $sections = $rows->pluck('section_id')->filter()->unique()->mapWithKeys(fn (string $id) => [$id => $this->academic->section($company, $id)['name'] ?? ''])->all();
        $levels = $rows->pluck('level_id')->filter()->unique()->mapWithKeys(fn (string $id) => [$id => $this->academic->level($company, $id)['name'] ?? []])->all();
        $attendance = app()->bound(StudentAttendance::class) ? app(StudentAttendance::class)->forSession($company, $sessionId, $studentIds) : [];

        // An exam's parts (creative, MCQ…) with each student's marks in them.
        $parts = collect();
        $marks = collect();
        if ($source instanceof Exam) {
            $paperIds = $subjectRows->flatten()->pluck('paper_id')->filter()->unique()->values()->all();
            $parts = $this->office->query(PaperPart::class, $company)->whereIn('paper_id', $paperIds)->orderBy('sort_order')->get()->groupBy('paper_id');
            $marks = $this->office->query(Mark::class, $company)->whereIn('paper_id', $paperIds)->whereIn('student_id', $studentIds)->get()
                ->keyBy(fn (Mark $mark) => "{$mark->student_id}:{$mark->part_id}");
        }
        $partsOf = function (Result $row, ?string $paperId) use ($parts, $marks): array {
            $list = collect($paperId === null ? [] : ($parts[$paperId] ?? []));
            if ($list->count() < 2) {
                return [];
            }

            return $list->map(function (PaperPart $part) use ($row, $marks) {
                $mark = $marks["{$row->student_id}:{$part->getKey()}"] ?? null;

                return ['code' => $part->code, 'name' => $part->texts('name'), 'full_marks_centi' => $part->full_marks_centi, 'marks_centi' => $mark?->marks_centi, 'absent' => (bool) $mark?->absent];
            })->values()->all();
        };

        return [
            'source' => [
                'type' => $source instanceof Exam ? 'exam' : 'combination', 'id' => $source->getKey(), 'name' => $source->texts('name'),
                'kind' => $source instanceof Exam ? $source->kind : null, 'session_id' => $sessionId, 'status' => $source->status,
                // Names go with the data: a family in the portal has no exam setup to look them up.
                'session' => $this->academic->session($company, $sessionId)['name'] ?? [],
                'starts_on' => $source instanceof Exam ? $source->starts_on?->toDateString() : null,
                'ends_on' => $source instanceof Exam ? $source->ends_on?->toDateString() : null,
            ],
            'institution' => ['name' => $company->texts('name'), 'unit' => $unit->getKey() === $company->getKey() ? null : $unit->texts('name')],
            'scheme' => $scheme === null ? null : ['name' => $scheme->texts('name'), 'max_point_centi' => $scheme->max_point_centi, 'bands' => $bands],
            'signatures' => array_values((array) $this->rules->get('education_exams.marksheet_signatures', $context)),
            'show_rank' => $showRank,
            'students' => $rows->sortBy(fn (Result $row) => [$sections[$row->section_id] ?? '', (int) ($rolls[$row->student_id] ?? PHP_INT_MAX), $students[$row->student_id]['name'] ?? ''])->values()
                ->map(fn (Result $row) => [
                    'result_id' => $row->getKey(),
                    'student' => array_intersect_key($students[$row->student_id] ?? [], array_flip(['id', 'code', 'name', 'name_local'])),
                    'roll' => $rolls[$row->student_id] ?? null,
                    'level_id' => $row->level_id, 'level' => $levels[$row->level_id] ?? [], 'section' => $sections[$row->section_id] ?? null,
                    ...$row->only(['marks_centi', 'full_marks_centi', 'percent_bp', 'gpa_centi', 'letter', 'passed', 'failed_subjects', 'status']),
                    'rank' => $showRank ? $row->rank : null, 'section_rank' => $showRank ? $row->section_rank : null,
                    'attendance' => $attendance[$row->student_id] ?? null,
                    'subjects' => collect($subjectRows[$row->getKey()] ?? [])
                        ->sortBy(fn ($subject) => [$subject->kind === 'optional' ? 1 : 0, $subjects[$subject->subject_id]['code'] ?? ''])->values()
                        ->map(fn ($subject) => [
                            ...$subject->only(['subject_id', 'kind', 'marks_centi', 'full_marks_centi', 'percent_bp', 'letter', 'point_centi', 'credits_centi', 'passed', 'absent']),
                            'subject' => array_intersect_key($subjects[$subject->subject_id] ?? [], array_flip(['id', 'code', 'name'])),
                            'parts' => $partsOf($row, $subject->paper_id),
                        ])->all(),
                ])->all(),
        ];
    }
}
