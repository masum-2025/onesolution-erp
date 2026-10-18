<?php

namespace Modules\StudentAttendance\Offline;

use App\Platform\Offline\Contracts\SyncableRecords;
use App\Platform\Offline\SyncOperation;
use App\Platform\Offline\SyncResult;
use App\Platform\Tenancy\Exceptions\TenancyException;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Education\Directory\AcademicDirectory;
use Modules\StudentAttendance\Http\Requests\SheetRequest;
use Modules\StudentAttendance\Models\Period;
use Modules\StudentAttendance\Services\Calendar;
use Modules\StudentAttendance\Services\Register;
use Modules\StudentAttendance\Services\Sheets;

/**
 * A section's sheet taken on a phone without a network (kind
 * student_attendance.sheet; not money). The phone keeps the sheet as
 * taken (section, day, period, each student's state) with the version it
 * started from, and sends it when back online; the same op id counts once.
 * Checked again as if just saved: a sheet someone saved meanwhile is a
 * conflict (never overwritten), a day now too old for a teacher or a
 * student no longer in the section is refused with its code.
 *
 * The phone keeps only what taking needs: the sections the person takes
 * (class teacher, or a subject there by period) with their students' names
 * and rolls, and the periods of those campuses. No phones, no guardians.
 */
class AttendanceSync implements SyncableRecords
{
    public function __construct(
        private Register $register,
        private Sheets $sheets,
        private Calendar $calendar,
        private AcademicDirectory $academic,
    ) {}

    public function key(): string
    {
        return 'student_attendance.sheet';
    }

    public function isMoney(): bool
    {
        return false;
    }

    public function permission(string $action): string
    {
        return 'student_attendance.take';
    }

    public function rules(): array
    {
        return ['student_attendance.by_period', 'student_attendance.edit_days', 'student_attendance.weekend_days'];
    }

    public function apply(SyncOperation $operation): SyncResult
    {
        if ($operation->action !== SyncOperation::CREATE) {
            return SyncResult::rejected('create_only');
        }
        $unit = $operation->organization;
        if (! Gate::forUser($operation->user)->allows('student_attendance.take', $unit) && ! Gate::forUser($operation->user)->allows('student_attendance.manage', $unit)) {
            return SyncResult::rejected('forbidden');
        }
        $fields = array_diff_key((new SheetRequest)->rules(), ['op_id' => true, 'base_version' => true]);
        $validator = Validator::make($operation->data, [
            'section_id' => ['required', 'string', 'size:26'],
            'date' => ['required', 'date_format:Y-m-d'],
            ...$fields,
        ]);
        if ($validator->fails()) {
            return SyncResult::rejected('invalid', $validator->errors()->toArray());
        }
        $data = $validator->validated();
        try {
            [$sheet] = $this->sheets->save($this->register->companyOf($unit), $unit, $operation->user, $data['section_id'],
                CarbonImmutable::createFromFormat('!Y-m-d', $data['date'], 'UTC'),
                [...$data, 'op_id' => $operation->opId, 'base_version' => $operation->baseVersion]);
        } catch (ValidationException $exception) {
            return SyncResult::rejected('invalid', $exception->errors());
        } catch (TenancyException $exception) {
            if ($exception->errorCode() === 'version_conflict') {
                $current = $exception->render()->getData(true)['current'] ?? [];

                return SyncResult::conflict((string) ($current['id'] ?? ''), (int) ($current['version'] ?? 0), $current);
            }

            return SyncResult::rejected($exception->errorCode());
        }

        return SyncResult::applied($sheet->getKey(), $sheet->version);
    }

    /** The sections the person may take at the unit, with their students, and those campuses' periods. */
    public function changes(Organization $organization, ?CarbonImmutable $since, int $limit): array
    {
        $company = $this->register->companyOf($organization);
        $units = $this->register->subtreeIds($organization);
        $user = request()->user();
        $sections = collect($this->academic->sessions($company))->where('status', 'open')
            ->flatMap(fn (array $session) => $this->academic->sessionSections($company, $session['id'], $units))
            ->filter(fn (array $section) => $user !== null && $this->sheets->mayTake($company, $organization, $user, $section, null, $this->sheets->byPeriod($company, $section['unit_id'])))
            ->take($limit)->values();
        $periods = $this->calendar->periods($company, $sections->pluck('unit_id')->unique()->values()->all(), activeOnly: true);

        return ['records' => [
            ...$sections->map(fn (array $section) => [
                'id' => $section['id'], 'type' => 'section', 'name' => $section['name'], 'unit_id' => $section['unit_id'],
                'level_id' => $section['level_id'], 'by_period' => $this->sheets->byPeriod($company, $section['unit_id']),
                'subjects' => $user === null ? [] : $this->sheets->mySubjects($company, $user, $section),
                'students' => $this->sheets->roster($company, $section)->map(fn (array $student) => [
                    'id' => $student['id'], 'name' => $student['name'], 'name_local' => $student['name_local'], 'roll' => $student['roll'],
                ])->values()->all(),
            ])->all(),
            ...$periods->map(fn (Period $period) => [
                'id' => $period->getKey(), 'type' => 'period', 'unit_id' => $period->unit_id, 'number' => $period->number,
                'name' => $period->texts('name'), 'starts_at' => $period->starts_at, 'ends_at' => $period->ends_at,
            ])->all(),
        ], 'deleted' => []];
    }
}
