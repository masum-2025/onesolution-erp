<?php

namespace Modules\EducationExams\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\EducationExams\Models\Exam;
use Modules\EducationExams\Models\MarkSheet;
use Modules\EducationExams\Models\Paper;
use Modules\EducationExams\Services\ExamOffice;
use Modules\EducationExams\Services\MarkSheets;

/**
 * Marks still owed: mark sheets of exams open for marks whose paper was
 * sat before today and that are not handed in (or were sent back), all of
 * them for who manages or moderates, one's own for a teacher. In the bell:
 * one's own sheets due, sheets handed in for a moderator (never their own),
 * and exams closed for marks waiting to be published.
 */
final class ExamWidgets implements AttentionProvider, DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $place = ExamPlace::of($context);
        if ($place === null) {
            return WidgetData::stat(0);
        }
        $due = $this->due($place, $place->seesAll ? null : $this->mine($place, $context));

        return WidgetData::stat($due->count(), hint: __('education_exams::dashboard.marks_due_hint'));
    }

    public function items(CurrentContext $context): array
    {
        $place = ExamPlace::of($context);
        if ($place === null) {
            return [];
        }
        $office = app(ExamOffice::class);
        $me = $context->user()?->getKey();
        $items = [];
        if (Gate::allows('education_exams.enter_marks', $place->unit)) {
            $mine = $this->due($place, $this->mine($place, $context))->count();
            $items[] = $mine === 0 ? null : new AttentionItem('education_exams.marks_due', __('education_exams::dashboard.attention_marks_due'), $mine, '/exams/my-sheets', 'warn');
        }
        if (Gate::allows('education_exams.moderate', $place->unit)) {
            $handedIn = $office->query(MarkSheet::class, $place->company)->whereIn('unit_id', $place->units)->where('status', 'submitted')
                ->where(fn ($query) => $query->whereNull('submitted_by')->orWhere('submitted_by', '!=', $me))->count();
            $items[] = $handedIn === 0 ? null : new AttentionItem('education_exams.sheets_waiting', __('education_exams::dashboard.attention_sheets'), $handedIn, '/exams', 'warn');
        }
        if (Gate::allows('education_exams.publish', $place->unit)) {
            $closed = $office->query(Exam::class, $place->company)->whereIn('unit_id', $place->units)->where('status', 'closed')->pluck('id');
            $items[] = $closed->isEmpty() ? null : new AttentionItem('education_exams.to_publish', __('education_exams::dashboard.attention_publish'), $closed->count(), $closed->count() === 1 ? "/exams/{$closed->first()}" : '/exams', 'info');
        }

        return array_values(array_filter($items));
    }

    /**
     * Sheets not handed in whose paper was sat before today, of exams open for marks.
     *
     * @param  Collection<int, string>|null  $only  Sheet ids to keep (a teacher's own); null for all.
     * @return Collection<int, MarkSheet>
     */
    private function due(ExamPlace $place, ?Collection $only): Collection
    {
        $office = app(ExamOffice::class);
        $today = $office->today($place->company)->toDateString();
        $exams = $office->query(Exam::class, $place->company)->whereIn('unit_id', $place->examUnits)->where('status', 'marking')->pluck('id');
        $sat = $office->query(Paper::class, $place->company)->whereIn('exam_id', $exams)->whereNotNull('sits_on')->where('sits_on', '<', $today)->pluck('id');

        return $office->query(MarkSheet::class, $place->company)->whereIn('unit_id', $place->units)->whereIn('paper_id', $sat)
            ->whereIn('status', ['open', 'returned'])
            ->when($only !== null, fn ($query) => $query->whereIn('id', $only))->get(['id']);
    }

    /** @return Collection<int, string> */
    private function mine(ExamPlace $place, CurrentContext $context): Collection
    {
        return $context->user() === null ? collect() : app(MarkSheets::class)->forTeacher($place->company, $place->units, $context->user())->pluck('id');
    }
}
