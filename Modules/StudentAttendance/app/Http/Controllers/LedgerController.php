<?php

namespace Modules\StudentAttendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Education\Directory\AcademicDirectory;
use Modules\StudentAttendance\Exceptions\StudentAttendanceException;
use Modules\StudentAttendance\Http\AttendancePresenter;
use Modules\StudentAttendance\Http\Controllers\Concerns\AttendancePlace;
use Modules\StudentAttendance\Models\Holiday;
use Modules\StudentAttendance\Models\Mark;
use Modules\StudentAttendance\Models\Sheet;
use Modules\StudentAttendance\Services\Calendar;
use Modules\StudentAttendance\Services\DayLedger;
use Modules\StudentAttendance\Services\Register;
use Modules\StudentAttendance\Services\Sheets;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Counting days: one student's month (their page in Education), and a
 * section's month as a register (on screen, or CSV for the office). Needs
 * student_attendance.view and a student or section the person sees.
 */
class LedgerController extends Controller
{
    use AttendancePlace;

    public function __construct(
        private Register $register,
        private Sheets $sheets,
        private DayLedger $ledger,
        private Calendar $calendar,
        private AcademicDirectory $academic,
        private AttendancePresenter $presenter,
    ) {}

    public function student(Request $request, string $organization, string $student): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('student_attendance.view', $unit);
        $found = $this->academic->student($company, $student);
        $enrollment = $found === null ? null : $this->academic->enrollment($company, $student);
        $visible = $this->sheets->visibleSections($company, $unit, $request->user());
        if ($enrollment === null || ! in_array($enrollment['unit_id'], $this->register->subtreeIds($unit), true)
            || ! $this->academic->studentInSections($company, $visible, $student)) {
            throw StudentAttendanceException::notFound('student');
        }
        [$from, $to] = $this->month((string) $request->query('month', $this->register->today($company)->format('Y-m')));
        $days = $this->ledger->days($company, [$student], $from, $to)[$student] ?? [];

        return response()->json(['data' => [
            'student' => ['id' => $found['id'], 'name' => $found['name'], 'name_local' => $found['name_local'], 'code' => $found['code']],
            'month' => $from->format('Y-m'),
            'days' => collect($days)->map(fn (array $day, string $date) => ['date' => $date, 'status' => $day['status'], 'periods' => $day['periods']])->values(),
            'totals' => $this->ledger->totals($company, $days),
            'holidays' => $this->calendar->holidays($company, [$enrollment['unit_id']], $from, $to)
                ->filter(fn (Holiday $holiday) => $holiday->level_ids === null || in_array($enrollment['level_id'], $holiday->level_ids, true))
                ->map(fn (Holiday $holiday) => $this->presenter->holiday($holiday))->values(),
            'weekend_days' => array_values((array) $this->register->rule('student_attendance.weekend_days', $company, $enrollment['unit_id'])),
        ]]);
    }

    /** A section's month: school days (days a sheet was taken), each student's state per day and totals. ?format=csv downloads it. */
    public function register(Request $request, string $organization): JsonResponse|StreamedResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('student_attendance.view', $unit);
        $query = $request->validate([
            'section_id' => ['required', 'string', 'size:26'],
            'month' => ['nullable', 'string'],
            'format' => ['nullable', 'in:json,csv'],
        ]);
        $section = $this->sheets->section($company, $unit, $query['section_id']);
        $visible = $this->sheets->visibleSections($company, $unit, $request->user());
        if ($visible !== null && ! in_array($section['id'], $visible, true)) {
            throw StudentAttendanceException::notFound('section');
        }
        [$from, $to] = $this->month($query['month'] ?? $this->register->today($company)->format('Y-m'));
        $sheets = $this->register->query(Sheet::class, $company)->where('section_id', $section['id'])
            ->whereDate('taken_on', '>=', $from->toDateString())->whereDate('taken_on', '<=', $to->toDateString())->get(['id', 'taken_on']);
        $dates = $sheets->map(fn (Sheet $sheet) => $sheet->taken_on->toDateString())->unique()->sort()->values()->all();
        $students = $this->sheets->roster($company, $section, $this->markedStudents($company, $sheets->pluck('id')->all()));
        $days = $this->ledger->days($company, $students->pluck('id')->all(), $from, $to);
        $rows = $students->map(fn (array $student) => [
            ...$student,
            'days' => (object) collect($days[$student['id']] ?? [])->only($dates)->map(fn (array $day) => $day['status'])->all(),
            'totals' => $this->ledger->totals($company, collect($days[$student['id']] ?? [])->only($dates)->all()),
        ])->values();

        if (($query['format'] ?? 'json') === 'csv') {
            return $this->csv($section['name'], $from->format('Y-m'), $dates, $rows->all());
        }

        return response()->json(['data' => [
            'section' => ['id' => $section['id'], 'name' => $section['name'], 'level_id' => $section['level_id']],
            'month' => $from->format('Y-m'),
            'dates' => $dates,
            'students' => $rows,
        ]]);
    }

    /**
     * Students with a mark on these sheets (some may have left the section since).
     *
     * @param  list<string>  $sheetIds
     * @return list<string>
     */
    private function markedStudents(Organization $company, array $sheetIds): array
    {
        return $sheetIds === [] ? [] : $this->register->query(Mark::class, $company)
            ->whereIn('sheet_id', $sheetIds)->distinct()->pluck('student_id')->all();
    }

    /**
     * @param  list<string>  $dates
     * @param  list<array<string, mixed>>  $rows
     */
    private function csv(string $section, string $month, array $dates, array $rows): StreamedResponse
    {
        $short = fn (string $status) => __("student_attendance::attendance.short.{$status}");
        $file = 'attendance-'.preg_replace('/[^A-Za-z0-9-]+/', '-', $section)."-{$month}.csv";

        return response()->streamDownload(function () use ($dates, $rows, $short) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                __('student_attendance::attendance.csv.roll'), __('student_attendance::attendance.csv.name'), __('student_attendance::attendance.csv.code'),
                ...array_map(fn (string $date) => substr($date, 8, 2), $dates),
                $short('present'), $short('absent'), $short('late'), $short('leave'), __('student_attendance::attendance.csv.days'),
            ]);
            foreach ($rows as $row) {
                $days = (array) $row['days'];
                fputcsv($out, [
                    $row['roll'], $row['name'], $row['code'],
                    ...array_map(fn (string $date) => isset($days[$date]) ? $short($days[$date]) : '', $dates),
                    $row['totals']['present'], $row['totals']['absent'], $row['totals']['late'], $row['totals']['leave'], $row['totals']['days'],
                ]);
            }
            fclose($out);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
