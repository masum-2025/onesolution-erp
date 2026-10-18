<?php

namespace Modules\StudentAttendance\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Support\Number;
use Modules\Education\Directory\AcademicDirectory;
use Modules\StudentAttendance\Services\DayLedger;
use Modules\StudentAttendance\Services\Register;

/**
 * Students present on fewer than the rule's share of their school days in
 * the last days (student_attendance.low_warn_bp, over LOOK_BACK days, from
 * MIN_DAYS school days on), the lowest first; each opens the student's
 * attendance. A teacher sees their own sections.
 */
final class LowAttendance implements DashboardWidget
{
    /** Days looked back. */
    private const LOOK_BACK = 30;

    /** School days a student needs before a share means anything. */
    private const MIN_DAYS = 5;

    private const SHOWN = 8;

    public function data(CurrentContext $context): array
    {
        $place = DashboardPlace::of($context);
        if ($place === null) {
            return WidgetData::list([]);
        }
        $students = array_slice($this->students($place), 0, self::SHOWN);
        $names = app(AcademicDirectory::class)->students($place->company, array_column($students, 'id'));

        return WidgetData::list(array_map(fn (array $row) => [
            'label' => (string) ($names[$row['id']]['name'] ?? ''),
            'meta' => __('student_attendance::dashboard.low_meta', [
                'share' => Number::percentage($row['share_bp'] / 100, locale: app()->getLocale()),
                'present' => Number::format($row['present'], locale: app()->getLocale()),
                'days' => Number::format($row['days'], locale: app()->getLocale()),
            ]),
            'path' => "/education/students/{$row['id']}?tab=attendance",
            'tone' => 'bad',
        ], $students));
    }

    /**
     * Students below the share, lowest first.
     *
     * @return list<array{id: string, present: int, days: int, share_bp: int}>
     */
    public function students(DashboardPlace $place): array
    {
        if ($place->sections->isEmpty()) {
            return [];
        }
        $register = app(Register::class);
        $ledger = app(DayLedger::class);
        $academic = app(AcademicDirectory::class);
        $limit = (int) $register->rule('student_attendance.low_warn_bp', $place->company, $place->unit->getKey());
        $today = $register->today($place->company);
        $ids = $place->sections->groupBy('session_id')->flatMap(fn ($sections, string $sessionId) => collect($academic->enrolledStudents($place->company, $sessionId, $place->units))
            ->filter(fn (array $row) => $sections->pluck('id')->contains($row['enrollment']['section_id']))
            ->pluck('student.id'))->unique()->values()->all();

        $low = [];
        foreach ($ledger->days($place->company, $ids, $today->subDays(self::LOOK_BACK - 1), $today) as $id => $days) {
            $totals = $ledger->totals($place->company, $days);
            if ($totals['days'] < self::MIN_DAYS) {
                continue;
            }
            $share = intdiv($totals['present'] * 10000, $totals['days']);
            if ($share < $limit) {
                $low[] = ['id' => $id, 'present' => $totals['present'], 'days' => $totals['days'], 'share_bp' => $share];
            }
        }
        usort($low, fn (array $a, array $b) => $a['share_bp'] <=> $b['share_bp']);

        return $low;
    }
}
