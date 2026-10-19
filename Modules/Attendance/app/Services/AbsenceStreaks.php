<?php

namespace Modules\Attendance\Services;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Modules\Attendance\Enums\DayStatus;
use Modules\Attendance\Models\AbsenceAlert;
use Modules\Attendance\Models\AttendanceDay;
use Modules\Hrm\Directory\EmployeeDirectory;

/**
 * People absent for many working days in a row (rule
 * attendance.absent_alert_days at their unit; 0 = never). Weekends,
 * holidays and days without a shift neither count nor break a streak; any
 * other day (present, late, a day not worked out) ends it. Each streak is
 * reported once, in one message per company and day to the people who
 * manage attendance there. Only names, codes and dates are sent.
 */
class AbsenceStreaks
{
    /** Days off neither count nor break a streak. */
    private const SKIPPED = [DayStatus::Weekend, DayStatus::Holiday, DayStatus::NoShift];

    public function __construct(
        private TenantDatabases $databases,
        private ModuleResolver $modules,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private Workplace $workplace,
        private EmployeeDirectory $directory,
        private Recipients $recipients,
        private Notifier $notifier,
    ) {}

    /**
     * The streak of absences ending on a day: how many working days, from which date.
     *
     * @param  array<string, DayStatus>  $statuses  By date (Y-m-d).
     * @return array{days: int, from: string|null}
     */
    public static function streak(array $statuses, CarbonImmutable $lastDay, int $lookBack): array
    {
        $days = 0;
        $from = null;
        for ($day = $lastDay, $seen = 0; $seen < $lookBack; $day = $day->subDay(), $seen++) {
            $status = $statuses[$day->toDateString()] ?? null;
            if ($status === DayStatus::Absent) {
                $days++;
                $from = $day->toDateString();
            } elseif ($status === null || ! in_array($status, self::SKIPPED, true)) {
                break;
            }
        }

        return ['days' => $days, 'from' => $from];
    }

    /** Report new streaks in every client database. Returns how many people were reported. */
    public function run(): int
    {
        $sent = 0;
        foreach ([null, ...$this->databases->names()] as $database) {
            $this->databases->onConnection($this->databases->connectionFor($database), function () use (&$sent) {
                $companies = AttendanceDay::query()->withoutGlobalScope(OrganizationScope::class)
                    ->where('status', DayStatus::Absent->value)->distinct()->pluck('organization_id');
                foreach (Organization::query()->whereKey($companies->all())->get() as $company) {
                    $sent += $this->runFor($company);
                }
            });
        }

        return $sent;
    }

    public function runFor(Organization $company): int
    {
        if (! $this->modules->isEnabled('attendance', $company)) {
            return 0;
        }
        $yesterday = $this->workplace->today($company)->subDay();
        $absent = $this->workplace->query(AttendanceDay::class, $company)
            ->where('work_date', $yesterday->toDateString())->where('status', DayStatus::Absent->value)->get(['employee_id', 'unit_id']);

        $found = [];
        $limits = [];
        foreach ($absent as $row) {
            $limit = $limits[$row->unit_id] ??= (int) $this->rules->get('attendance.absent_alert_days', $this->contexts->forOrganization(Organization::query()->findOrFail($row->unit_id)));
            if ($limit <= 0) {
                continue;
            }
            // Room for the weekends and holidays inside a streak of $limit working days.
            $lookBack = $limit * 2 + 14;
            $statuses = $this->workplace->query(AttendanceDay::class, $company)->where('employee_id', $row->employee_id)
                ->where('work_date', '>', $yesterday->subDays($lookBack)->toDateString())->where('work_date', '<=', $yesterday->toDateString())
                ->get(['work_date', 'status'])->mapWithKeys(fn (AttendanceDay $day) => [$day->work_date->toDateString() => $day->status])->all();
            $streak = self::streak($statuses, $yesterday, $lookBack);
            if ($streak['days'] < $limit || $this->workplace->query(AbsenceAlert::class, $company)
                ->where('employee_id', $row->employee_id)->where('streak_from', $streak['from'])->exists()) {
                continue;
            }
            $found[$row->employee_id] = ['unit_id' => $row->unit_id, ...$streak];
        }

        if ($found === []) {
            return 0;
        }

        $people = $this->directory->many($company, array_keys($found));
        foreach ($found as $employeeId => $streak) {
            AbsenceAlert::query()->withoutGlobalScope(OrganizationScope::class)->create([
                'organization_id' => $company->getKey(), 'unit_id' => $streak['unit_id'], 'employee_id' => $employeeId,
                'streak_from' => $streak['from'], 'days' => $streak['days'], 'sent_on' => $yesterday->addDay()->toDateString(),
            ]);
        }

        $this->notifier->notify(
            'attendance.absent_streak',
            $this->recipients->holding('attendance.manage', $company),
            fn (string $locale) => [
                'organization' => $company->displayName($locale),
                'count' => (string) count($found),
                'people' => collect($found)->map(fn (array $streak, string $id) => '• '.($people[$id]->name ?? '').' ('.($people[$id]->code ?? '').') — '
                    .__('attendance::notifications.since', ['days' => $streak['days'], 'date' => CarbonImmutable::parse($streak['from'])->locale($locale)->isoFormat('LL')], $locale))->implode("\n"),
            ],
            $company->partner,
            $company,
        );

        return count($found);
    }
}
