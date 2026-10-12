<?php

namespace Modules\EducationExams\Directory;

use App\Platform\Tenancy\Models\Organization;
use Modules\EducationExams\Models\Combination;
use Modules\EducationExams\Models\Exam;
use Modules\EducationExams\Models\Result;
use Modules\EducationExams\Models\ResultSubject;
use Modules\EducationExams\Services\ExamOffice;

/**
 * The exams module's public service for other modules (course registration,
 * the portal, documents): published results only, as plain arrays. Every
 * lookup names the institution.
 */
class ResultDirectory
{
    public function __construct(private ExamOffice $office) {}

    /**
     * Who passed which subject in a published exam or combination.
     *
     * @return array<string, array<string, bool>> student id => subject id => passed
     */
    public function subjectOutcomes(Organization $company, string $sourceType, string $sourceId): array
    {
        $results = $this->office->query(Result::class, $company)->where('source_type', $sourceType)->where('source_id', $sourceId)->where('status', 'published')->pluck('student_id', 'id');
        $outcomes = [];
        foreach ($this->office->query(ResultSubject::class, $company)->whereIn('result_id', $results->keys())->get() as $subject) {
            $outcomes[$results[$subject->result_id]][$subject->subject_id] = $subject->passed;
        }

        return $outcomes;
    }

    /**
     * A student's published results, newest first, with their subjects; and
     * the cumulative grade point over sessions (credit-weighted when subjects
     * have credits): each session counts its combination, else its final exams.
     *
     * @return array{results: list<array<string, mixed>>, cgpa_centi: ?int, credits_centi: int}
     */
    public function transcript(Organization $company, string $studentId): array
    {
        $results = $this->office->query(Result::class, $company)->where('student_id', $studentId)->where('status', 'published')->orderByDesc('published_at')->get();
        $subjects = $this->office->query(ResultSubject::class, $company)->whereIn('result_id', $results->pluck('id'))->get()->groupBy('result_id');
        $exams = $this->office->query(Exam::class, $company)->whereKey($results->where('source_type', 'exam')->pluck('source_id'))->get()->keyBy('id');
        $combinations = $this->office->query(Combination::class, $company)->whereKey($results->where('source_type', 'combination')->pluck('source_id'))->get()->keyBy('id');

        $counted = [];
        foreach ($results->groupBy('session_id') as $rows) {
            $chosen = $rows->where('source_type', 'combination');
            if ($chosen->isEmpty()) {
                $chosen = $rows->filter(fn (Result $row) => $row->source_type === 'exam' && ($exams[$row->source_id]->kind ?? null) === 'final');
            }
            foreach ($chosen as $row) {
                foreach ($subjects[$row->getKey()] ?? [] as $subject) {
                    if ($subject->kind !== 'optional') {
                        $counted[] = $subject;
                    }
                }
            }
        }
        $credits = (int) collect($counted)->sum('credits_centi');
        $weight = $credits > 0 ? $credits : 100 * count($counted);
        $points = (int) collect($counted)->sum(fn (ResultSubject $subject) => $subject->point_centi * ($credits > 0 ? $subject->credits_centi : 100));

        return [
            'results' => $results->map(fn (Result $result) => [
                ...$result->only(['id', 'source_type', 'source_id', 'session_id', 'level_id', 'marks_centi', 'full_marks_centi', 'percent_bp', 'gpa_centi', 'letter', 'passed', 'failed_subjects', 'rank', 'section_rank']),
                'name' => $result->source_type === 'exam' ? ($exams[$result->source_id]?->texts('name') ?? []) : ($combinations[$result->source_id]?->texts('name') ?? []),
                'published_at' => $result->published_at?->toIso8601String(),
                'subjects' => collect($subjects[$result->getKey()] ?? [])->map(fn (ResultSubject $subject) => $subject->only(['subject_id', 'kind', 'marks_centi', 'full_marks_centi', 'percent_bp', 'letter', 'point_centi', 'credits_centi', 'passed', 'absent']))->values()->all(),
            ])->values()->all(),
            'cgpa_centi' => $weight > 0 && $counted !== [] ? intdiv($points + intdiv($weight, 2), $weight) : null,
            'credits_centi' => $credits,
        ];
    }
}
