<?php

namespace Modules\EducationExams\Http;

use Illuminate\Support\Collection;
use Modules\EducationExams\Models\Exam;
use Modules\EducationExams\Models\GradingScheme;
use Modules\EducationExams\Models\MarkSheet;
use Modules\EducationExams\Models\Paper;
use Modules\EducationExams\Models\PaperPart;

/**
 * What the exam API shows: marks as hundredths, percentages as basis
 * points, grade points as hundredths, dates as Y-m-d, names in every language.
 */
class ExamPresenter
{
    /** @param  list<array<string, mixed>>  $bands */
    public function scheme(GradingScheme $scheme, array $bands = []): array
    {
        return [
            'id' => $scheme->getKey(),
            ...$scheme->only(['code', 'max_point_centi', 'is_active', 'version']),
            'name' => $scheme->texts('name'),
            'bands' => $bands,
        ];
    }

    /** @return array<string, mixed> */
    public function exam(Exam $exam, ?array $counts = null): array
    {
        return [
            'id' => $exam->getKey(),
            ...$exam->only(['unit_id', 'session_id', 'kind', 'grading_scheme_id', 'status', 'version']),
            'name' => $exam->texts('name'),
            'starts_on' => $exam->starts_on?->toDateString(),
            'ends_on' => $exam->ends_on?->toDateString(),
            ...($counts === null ? [] : ['counts' => $counts]),
        ];
    }

    /**
     * @param  Collection<int, PaperPart>  $parts
     * @param  array<string, mixed>|null  $subject
     * @return array<string, mixed>
     */
    public function paper(Paper $paper, Collection $parts, ?array $subject = null): array
    {
        return [
            'id' => $paper->getKey(),
            ...$paper->only(['exam_id', 'level_id', 'subject_id', 'kind', 'full_marks_centi', 'pass_marks_centi', 'starts_at', 'minutes', 'room', 'version']),
            'sits_on' => $paper->sits_on?->toDateString(),
            'subject' => $subject === null ? null : array_intersect_key($subject, array_flip(['id', 'code', 'name', 'credits_centi'])),
            'parts' => $parts->map(fn (PaperPart $part) => [...$part->only(['id', 'code', 'full_marks_centi', 'pass_marks_centi', 'sort_order']), 'name' => $part->texts('name')])->values(),
        ];
    }

    /** @return array<string, mixed> */
    public function sheet(MarkSheet $sheet, bool $mine = false): array
    {
        return [
            'id' => $sheet->getKey(),
            ...$sheet->only(['unit_id', 'exam_id', 'paper_id', 'section_id', 'teacher_id', 'status', 'submitted_by', 'approved_by', 'note', 'version']),
            'submitted_at' => $sheet->submitted_at?->toIso8601String(),
            'approved_at' => $sheet->approved_at?->toIso8601String(),
            'mine' => $mine,
        ];
    }
}
