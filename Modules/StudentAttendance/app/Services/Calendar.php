<?php

namespace Modules\StudentAttendance\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\StudentAttendance\Exceptions\StudentAttendanceException;
use Modules\StudentAttendance\Models\Holiday;
use Modules\StudentAttendance\Models\Period;
use Modules\StudentAttendance\Models\Sheet;

/**
 * A campus's periods of the day and the institution's days off: weekly
 * ones (rule student_attendance.weekend_days, read at the campus) and
 * holidays (the whole institution, one campus, or some classes). Changes
 * to periods and holidays are audited. A day off still lets attendance be
 * taken (an extra class); the screen says it is one.
 */
class Calendar
{
    /** Day names as the weekend rule writes them, by ISO weekday (1 = Monday). */
    private const DAYS = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];

    public function __construct(private Register $register, private AuditLogger $audit) {}

    /**
     * Periods of these campuses, in order.
     *
     * @param  list<string>  $unitIds
     * @return Collection<int, Period>
     */
    public function periods(Organization $company, array $unitIds, bool $activeOnly = false): Collection
    {
        return $this->register->query(Period::class, $company)->whereIn('unit_id', $unitIds)
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('unit_id')->orderBy('number')->get();
    }

    /**
     * Add (period null) or change a period. The number is unique at its campus.
     *
     * @param  array<string, mixed>  $data
     */
    public function savePeriod(Organization $company, ?Period $period, array $data, User $actor): Period
    {
        return $this->register->transaction($company, function () use ($company, $period, $data, $actor) {
            if ($period !== null) {
                $period = $this->register->query(Period::class, $company)->whereKey($period->getKey())->lockForUpdate()->firstOrFail();
                if ($period->version !== (int) $data['base_version']) {
                    throw StudentAttendanceException::versionConflict(['version' => $period->version]);
                }
                $old = $this->periodFacts($period);
                $period->fill(array_intersect_key($data, array_flip(['number', 'starts_at', 'ends_at', 'is_active'])));
                $period->version++;
            } else {
                $old = [];
                $period = new Period([
                    'organization_id' => $company->getKey(), 'unit_id' => $data['unit_id'], 'number' => $data['number'],
                    'starts_at' => $data['starts_at'] ?? null, 'ends_at' => $data['ends_at'] ?? null, 'is_active' => $data['is_active'] ?? true,
                ]);
            }
            if (array_key_exists('name', $data)) {
                $period->putTexts('name', array_filter($data['name'], fn ($text) => $text !== null && $text !== ''));
            }
            $taken = $this->register->query(Period::class, $company)->where('unit_id', $period->unit_id)->where('number', $period->number)
                ->when($period->exists, fn ($query) => $query->whereKeyNot($period->getKey()))->exists();
            if ($taken) {
                throw ValidationException::withMessages(['number' => __('student_attendance::attendance.validation.period_number_taken')]);
            }
            $period->save();
            $this->audit->record($old === [] ? 'student_attendance.period_added' : 'student_attendance.period_changed', $period,
                old: $old, new: $this->periodFacts($period), actor: $actor, organizationId: $company->getKey());

            return $period;
        });
    }

    /** Remove a period no sheet names; one in use is switched off instead (409 asks for that). */
    public function removePeriod(Organization $company, Period $period, int $baseVersion, User $actor): void
    {
        $this->register->transaction($company, function () use ($company, $period, $baseVersion, $actor) {
            $period = $this->register->query(Period::class, $company)->whereKey($period->getKey())->lockForUpdate()->firstOrFail();
            if ($period->version !== $baseVersion) {
                throw StudentAttendanceException::versionConflict(['version' => $period->version]);
            }
            if ($this->register->query(Sheet::class, $company)->where('period_id', $period->getKey())->exists()) {
                throw StudentAttendanceException::periodInUse();
            }
            $this->audit->record('student_attendance.period_removed', $period, old: $this->periodFacts($period), actor: $actor, organizationId: $company->getKey());
            $period->delete();
        });
    }

    /**
     * Holidays touching these days, for these campuses (and the whole institution's).
     *
     * @param  list<string>  $unitIds
     * @return Collection<int, Holiday>
     */
    public function holidays(Organization $company, array $unitIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->register->query(Holiday::class, $company)
            ->where(fn ($query) => $query->whereNull('unit_id')->orWhereIn('unit_id', $unitIds))
            ->whereDate('starts_on', '<=', $to->toDateString())->whereDate('ends_on', '>=', $from->toDateString())
            ->orderBy('starts_on')->get();
    }

    /** @param  array<string, mixed>  $data */
    public function addHoliday(Organization $company, array $data, User $actor): Holiday
    {
        $holiday = new Holiday([
            'organization_id' => $company->getKey(), 'unit_id' => $data['unit_id'] ?? null,
            'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'] ?? $data['starts_on'],
            'level_ids' => ($data['level_ids'] ?? []) === [] ? null : array_values(array_unique($data['level_ids'])),
            'created_by' => $actor->getKey(),
        ]);
        $holiday->putTexts('name', array_filter($data['name'], fn ($text) => $text !== null && $text !== ''));
        $holiday->save();
        $this->audit->record('student_attendance.holiday_added', $holiday, new: $this->holidayFacts($holiday), actor: $actor, organizationId: $company->getKey());

        return $holiday;
    }

    public function removeHoliday(Organization $company, Holiday $holiday, User $actor): void
    {
        $this->audit->record('student_attendance.holiday_removed', $holiday, old: $this->holidayFacts($holiday), actor: $actor, organizationId: $company->getKey());
        $holiday->delete();
    }

    /**
     * Whether a class at a campus is off on a day: a weekly day off, and/or a holiday's name.
     *
     * @return array{weekend: bool, holiday: array<string, string>|null}
     */
    public function dayOff(Organization $company, string $unitId, ?string $levelId, CarbonImmutable $day): array
    {
        $weekend = in_array(self::DAYS[$day->dayOfWeekIso], (array) $this->register->rule('student_attendance.weekend_days', $company, $unitId), true);
        $holiday = $this->holidays($company, [$unitId], $day, $day)
            ->first(fn (Holiday $holiday) => $holiday->level_ids === null || ($levelId !== null && in_array($levelId, $holiday->level_ids, true)));

        return ['weekend' => $weekend, 'holiday' => $holiday?->texts('name')];
    }

    /** @return array<string, mixed> */
    private function periodFacts(Period $period): array
    {
        return ['unit_id' => $period->unit_id, 'number' => $period->number, 'name' => $period->texts('name'),
            'starts_at' => $period->starts_at, 'ends_at' => $period->ends_at, 'is_active' => $period->is_active];
    }

    /** @return array<string, mixed> */
    private function holidayFacts(Holiday $holiday): array
    {
        return ['unit_id' => $holiday->unit_id, 'starts_on' => $holiday->starts_on->toDateString(), 'ends_on' => $holiday->ends_on->toDateString(),
            'name' => $holiday->texts('name'), 'level_ids' => $holiday->level_ids];
    }
}
