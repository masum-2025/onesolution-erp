<?php

namespace Modules\EducationLectures\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Support\Facades\Gate;
use Modules\EducationLectures\Models\Lecture;
use Modules\EducationLectures\Services\Classroom;

/**
 * Lectures published in the last seven days (with the week before to
 * compare) in the sections the person sees; in the bell, lectures waiting
 * for approval for those who manage lectures (never their own).
 */
final class LectureWidgets implements AttentionProvider, DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $place = LecturePlace::of($context);
        if ($place === null) {
            return WidgetData::stat(0);
        }
        $classroom = app(Classroom::class);
        $today = $classroom->today($place->company);
        $count = fn (int $from, int $to) => $classroom->query(Lecture::class, $place->company)->whereIn('unit_id', $place->units)->where('status', 'published')
            ->when($place->sections !== null, fn ($query) => $query->whereIn('section_id', $place->sections))
            ->whereBetween('lesson_on', [$today->subDays($from)->toDateString(), $today->subDays($to)->toDateString()])->count();
        $week = $count(6, 0);

        return WidgetData::stat($week, hint: __('education_lectures::dashboard.week_hint'), change: $week - $count(13, 7), upIsGood: true);
    }

    public function items(CurrentContext $context): array
    {
        $place = LecturePlace::of($context);
        if ($place === null || ! Gate::allows('education_lectures.manage', $place->unit)) {
            return [];
        }
        $waiting = app(Classroom::class)->query(Lecture::class, $place->company)->whereIn('unit_id', $place->units)->where('status', 'waiting')
            ->where('posted_by', '!=', $context->user()->getKey())->count();

        return $waiting === 0 ? [] : [new AttentionItem('education_lectures.waiting', __('education_lectures::dashboard.attention_waiting'), $waiting, '/lectures?status=waiting', 'warn')];
    }
}
