<?php

namespace Modules\StudentAttendance\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Support\Facades\Gate;
use Modules\StudentAttendance\Services\Calendar;
use Modules\StudentAttendance\Services\Register;
use Modules\StudentAttendance\Services\Sheets;

/**
 * Sections whose attendance is not taken yet today (of those the person
 * sees; a day off counts none). In the bell: the sections still to take
 * for whoever takes them (a teacher: their own), and, for those who manage
 * attendance, students below the rule's share of days present.
 */
final class AttendanceWidgets implements AttentionProvider, DashboardWidget
{
    public function data(CurrentContext $context): array
    {
        $place = DashboardPlace::of($context);
        if ($place === null) {
            return WidgetData::stat(0);
        }
        [$pending, $open] = $this->pending($place, mine: false);

        return WidgetData::stat(count($pending), hint: $open === 0
            ? __('student_attendance::dashboard.today_off')
            : __('student_attendance::dashboard.today_hint', ['taken' => $open - count($pending), 'total' => $open]));
    }

    public function items(CurrentContext $context): array
    {
        $place = DashboardPlace::of($context);
        if ($place === null) {
            return [];
        }
        $items = [];
        if (Gate::allows('student_attendance.take', $place->unit) || Gate::allows('student_attendance.manage', $place->unit)) {
            [$pending] = $this->pending($place, mine: true);
            if ($pending !== []) {
                $items[] = new AttentionItem('student_attendance.pending', __('student_attendance::dashboard.attention_pending'), count($pending), '/student-attendance', 'warn');
            }
        }
        if (Gate::allows('student_attendance.manage', $place->unit)) {
            $low = count(app(LowAttendance::class)->students($place));
            if ($low > 0) {
                $items[] = new AttentionItem('student_attendance.low', __('student_attendance::dashboard.attention_low'), $low, '/student-attendance/register', 'bad');
            }
        }

        return $items;
    }

    /**
     * Sections not taken today (mine: only those the person takes), and how many are open today (not off).
     *
     * @return array{0: list<string>, 1: int}
     */
    private function pending(DashboardPlace $place, bool $mine): array
    {
        $register = app(Register::class);
        $sheets = app(Sheets::class);
        $calendar = app(Calendar::class);
        $today = $register->today($place->company);
        $open = $place->sections->filter(function (array $section) use ($calendar, $place, $today) {
            $off = $calendar->dayOff($place->company, $section['unit_id'], $section['level_id'], $today);

            return ! $off['weekend'] && $off['holiday'] === null;
        });
        if ($mine) {
            $open = $open->filter(fn (array $section) => $sheets->mayTake($place->company, $place->unit, $place->user, $section, null, $sheets->byPeriod($place->company, $section['unit_id'])));
        }
        $taken = $sheets->sheetsOn($place->company, $open->pluck('id')->all(), $today)->keys()->all();

        return [$open->pluck('id')->diff($taken)->values()->all(), $open->count()];
    }
}
