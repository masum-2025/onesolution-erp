<?php

namespace Modules\StudentAttendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Portal\PortalAccess;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Education\Directory\AcademicDirectory;
use Modules\StudentAttendance\Exceptions\StudentAttendanceException;
use Modules\StudentAttendance\Http\AttendancePresenter;
use Modules\StudentAttendance\Http\Controllers\Concerns\AttendancePlace;
use Modules\StudentAttendance\Models\Holiday;
use Modules\StudentAttendance\Services\Calendar;
use Modules\StudentAttendance\Services\DayLedger;
use Modules\StudentAttendance\Services\Register;

/**
 * A student's attendance in the client's portal (B2B2C): their own (their
 * "self" link) or, with ?record=, a child a guardian is linked to; a month
 * of days, the totals, holidays and weekly days off. Anything else is the
 * same 404.
 */
class PortalAttendanceController extends Controller
{
    use AttendancePlace;

    public function __construct(
        private CurrentContext $context,
        private PortalAccess $portal,
        private Register $register,
        private AcademicDirectory $academic,
        private DayLedger $ledger,
        private Calendar $calendar,
        private AttendancePresenter $presenter,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['record' => ['nullable', 'string', 'size:26'], 'month' => ['nullable', 'string']]);
        if (! $this->portal->isPortal()) {
            throw StudentAttendanceException::notFound('student');
        }
        $membership = $this->context->membership();
        $link = PortalLink::query()->where('membership_id', $membership->getKey())->where('organization_id', $membership->organization_id)
            ->where('subject_type', 'education.student')->where('status', PortalLink::ACTIVE)
            ->when(($data['record'] ?? null) === null, fn ($query) => $query->where('relation', 'self'), fn ($query) => $query->whereKey($data['record']))
            ->first(['subject_id']);
        $company = $this->register->companyOf($this->context->organization());
        $student = $link === null ? null : $this->academic->student($company, $link->subject_id);
        $enrollment = $student === null ? null : $this->academic->enrollment($company, $student['id']);
        if ($student === null || $enrollment === null) {
            throw StudentAttendanceException::notFound('student');
        }
        [$from, $to] = $this->month($data['month'] ?? $this->register->today($company)->format('Y-m'));
        $days = $this->ledger->days($company, [$student['id']], $from, $to)[$student['id']] ?? [];

        return response()->json(['data' => [
            'student' => ['id' => $student['id'], 'name' => $student['name'], 'name_local' => $student['name_local'], 'code' => $student['code']],
            'month' => $from->format('Y-m'),
            // Periods are left out: families see the day.
            'days' => collect($days)->map(fn (array $day, string $date) => ['date' => $date, 'status' => $day['status']])->values(),
            'totals' => $this->ledger->totals($company, $days),
            'holidays' => $this->calendar->holidays($company, [$enrollment['unit_id']], $from, $to)
                ->filter(fn (Holiday $holiday) => $holiday->level_ids === null || in_array($enrollment['level_id'], $holiday->level_ids, true))
                ->map(fn (Holiday $holiday) => $this->presenter->holiday($holiday))->values(),
            'weekend_days' => array_values((array) $this->register->rule('student_attendance.weekend_days', $company, $enrollment['unit_id'])),
        ]]);
    }
}
