<?php

namespace Modules\EducationExams\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use Modules\Education\Directory\AcademicDirectory;
use Modules\EducationExams\Models\Exam;
use Modules\EducationExams\Models\Paper;
use Modules\EducationExams\Services\ExamOffice;
use Modules\EducationExams\Services\MarkSheets;

/**
 * Papers sat in the coming days (rule education_exams.upcoming_days), soonest
 * first: subject and class, the exam, the day; each opens its exam. Someone
 * who only marks sees the papers of their own sheets.
 */
final class UpcomingPapers implements DashboardWidget
{
    private const SHOWN = 8;

    public function data(CurrentContext $context): array
    {
        $place = ExamPlace::of($context);
        if ($place === null) {
            return WidgetData::list([]);
        }
        $office = app(ExamOffice::class);
        $today = $office->today($place->company);
        $days = (int) app(RuleResolver::class)->get('education_exams.upcoming_days', app(RuleContextFactory::class)->forOrganization($place->unit));

        $exams = $office->query(Exam::class, $place->company)->whereIn('unit_id', $place->examUnits)->whereIn('status', ['draft', 'marking'])->get()->keyBy('id');
        $papers = $office->query(Paper::class, $place->company)->whereIn('exam_id', $exams->keys())
            ->whereBetween('sits_on', [$today->toDateString(), $today->addDays($days)->toDateString()])
            ->when(! $place->seesAll, function ($query) use ($place, $context) {
                $mine = app(MarkSheets::class)->forTeacher($place->company, $place->units, $context->user());
                $query->whereIn('id', $mine->pluck('paper_id')->unique()->values());
            })
            ->orderBy('sits_on')->orderBy('starts_at')->limit(self::SHOWN)->get();

        $academic = app(AcademicDirectory::class);
        $subjects = $academic->subjects($place->company, $papers->pluck('subject_id')->unique()->values()->all());
        $levels = collect($academic->levels($place->company))->keyBy('id');

        return WidgetData::list($papers->map(fn (Paper $paper) => [
            'label' => trim(ExamPlace::text($subjects[$paper->subject_id]['name'] ?? []).' · '.ExamPlace::text($levels[$paper->level_id]['name'] ?? []), ' ·'),
            'meta' => $exams[$paper->exam_id]->name,
            'date' => $paper->sits_on?->toDateString(),
            'path' => "/exams/{$paper->exam_id}",
        ])->all());
    }
}
