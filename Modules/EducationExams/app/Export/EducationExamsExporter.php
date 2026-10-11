<?php

namespace Modules\EducationExams\Export;

use App\Platform\DataExport\Contracts\ExportsModuleData;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Modules\EducationExams\Models\Exam;
use Modules\EducationExams\Models\GradingBand;
use Modules\EducationExams\Models\GradingScheme;
use Modules\EducationExams\Models\Mark;
use Modules\EducationExams\Models\MarkSheet;
use Modules\EducationExams\Models\Paper;
use Modules\EducationExams\Models\PaperPart;

/**
 * Exams in the client's data export: grading schemes and bands, exams,
 * papers and their parts, mark sheets and every mark.
 */
class EducationExamsExporter implements ExportsModuleData
{
    public function moduleKey(): string
    {
        return 'education_exams';
    }

    public function export(Organization $organization, array $organizationIds): array
    {
        $plain = fn (Model $row, array $columns) => array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value, $row->only(['id', ...$columns]));

        return [
            'grading_schemes' => $this->rows(GradingScheme::class, $organization, $organizationIds, fn ($row) => [...$plain($row, ['code', 'max_point_centi', 'is_active']), 'name' => $row->texts('name')]),
            'grading_bands' => $this->rows(GradingBand::class, $organization, $organizationIds, fn ($row) => $plain($row, ['scheme_id', 'min_percent_bp', 'letter', 'point_centi', 'is_pass'])),
            'exams' => $this->rows(Exam::class, $organization, $organizationIds, fn ($row) => [...$plain($row, ['unit_id', 'session_id', 'kind', 'starts_on', 'ends_on', 'grading_scheme_id', 'status']), 'name' => $row->texts('name')]),
            'papers' => $this->rows(Paper::class, $organization, $organizationIds, fn ($row) => $plain($row, ['exam_id', 'level_id', 'subject_id', 'kind', 'full_marks_centi', 'pass_marks_centi', 'sits_on', 'starts_at', 'minutes', 'room'])),
            'paper_parts' => $this->rows(PaperPart::class, $organization, $organizationIds, fn ($row) => [...$plain($row, ['paper_id', 'code', 'full_marks_centi', 'pass_marks_centi']), 'name' => $row->texts('name')]),
            'mark_sheets' => $this->rows(MarkSheet::class, $organization, $organizationIds, fn ($row) => $plain($row, ['unit_id', 'exam_id', 'paper_id', 'section_id', 'teacher_id', 'status', 'submitted_at', 'approved_at', 'note'])),
            'marks' => $this->rows(Mark::class, $organization, $organizationIds, fn ($row) => $plain($row, ['sheet_id', 'paper_id', 'part_id', 'student_id', 'marks_centi', 'absent'])),
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
