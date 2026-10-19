<?php

namespace Modules\Leave\Services;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Modules\Attendance\Services\AttendanceSummary;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Hrm\Directory\EmployeeRecord;
use Modules\Leave\Models\LeaveEntry;
use Modules\Leave\Models\LeaveType;

/**
 * Leave coming in, once a day for every employee and active type they may
 * take (each ledger source is written once, so running twice changes
 * nothing):
 *
 * - at a new leave year, what is left of the last one up to the type's
 *   carry limit ("carry-{year}");
 * - yearly types: the year's leave ("year-{year}"), a share for joiners;
 * - monthly types: up to a twelfth for each month begun ("month-{Y-m}");
 * - earned leave (worked_days): after each month, one day for every so
 *   many days at work in the year so far, from Attendance's summary
 *   ("worked-{Y-m}"); nothing comes while Attendance is off.
 */
class Accruals
{
    public function __construct(
        private TenantDatabases $databases,
        private ModuleResolver $modules,
        private Place $place,
        private Balances $balances,
        private EmployeeDirectory $directory,
    ) {}

    /** Every company with leave types, in every client database. Returns the ledger lines written. */
    public function run(): int
    {
        $written = 0;
        foreach ([null, ...$this->databases->names()] as $database) {
            $this->databases->onConnection($this->databases->connectionFor($database), function () use (&$written) {
                $companies = LeaveType::query()->withoutGlobalScope(OrganizationScope::class)->where('is_active', true)->distinct()->pluck('organization_id');
                foreach (Organization::query()->whereKey($companies->all())->get() as $company) {
                    $written += $this->runFor($company);
                }
            });
        }

        return $written;
    }

    public function runFor(Organization $company, ?CarbonImmutable $today = null): int
    {
        if (! $this->modules->isEnabled('leave', $company)) {
            return 0;
        }
        $today ??= $this->place->today($company);
        $types = $this->place->query(LeaveType::class, $company)->where('is_active', true)->get();
        $employees = $this->directory->inUnits($company, $this->place->subtreeIds($company), $today);
        $written = 0;
        foreach ($employees as $employee) {
            $unit = Organization::query()->find($employee->unitId);
            if ($unit === null) {
                continue;
            }
            $startMonth = $this->balances->startMonth($unit);
            foreach ($types->filter(fn (LeaveType $type) => $type->openTo($employee->gender)) as $type) {
                $written += $this->accrue($company, $employee, $type, $today, $startMonth);
            }
        }

        return $written;
    }

    private function accrue(Organization $company, EmployeeRecord $employee, LeaveType $type, CarbonImmutable $today, int $startMonth): int
    {
        $year = LeavePolicy::yearOf($today, $startMonth);
        $written = 0;

        if ($type->carry_max_halves > 0 && $employee->joinedOn->lessThan(LeavePolicy::yearStart($year, $startMonth))) {
            $left = $this->balances->balance($company, $employee->id, $type->getKey(), $year - 1);
            $carried = LeavePolicy::carried($left, $type->carry_max_halves);
            $written += $this->line($company, $employee, $type, $year, LeaveEntry::CARRIED, $carried, "carry-{$year}");
        }

        $target = match ($type->accrual) {
            'yearly' => ['year-'.$year, LeavePolicy::yearlyShare($type->allowance_halves, $employee->joinedOn, $year, $startMonth)],
            'monthly' => ['month-'.$today->format('Y-m'), LeavePolicy::monthlyTarget($type->allowance_halves, $employee->joinedOn, $today, $year, $startMonth)],
            'worked_days' => $this->earned($company, $employee, $type, $today, $year, $startMonth),
            default => null,
        };
        if ($target !== null) {
            [$source, $total] = $target;
            $given = (int) $this->place->query(LeaveEntry::class, $company)->where('employee_id', $employee->id)->where('type_id', $type->getKey())
                ->where('leave_year', $year)->where('kind', LeaveEntry::ACCRUAL)->sum('halves');
            $written += $this->line($company, $employee, $type, $year, LeaveEntry::ACCRUAL, max(0, $total - $given), $source, $type->accrual === 'worked_days');
        }

        return $written;
    }

    /**
     * Earned leave for the months of the year finished so far: one source per month, once.
     *
     * @return array{0: string, 1: int}|null
     */
    private function earned(Organization $company, EmployeeRecord $employee, LeaveType $type, CarbonImmutable $today, int $year, int $startMonth): ?array
    {
        $start = LeavePolicy::yearStart($year, $startMonth);
        $lastMonth = $today->startOfMonth()->subMonth();
        if ($lastMonth->lessThan($start) || ! class_exists(AttendanceSummary::class) || ! $this->modules->isEnabled('attendance', $company)) {
            return null;
        }
        $source = 'worked-'.$lastMonth->format('Y-m');
        $done = $this->place->query(LeaveEntry::class, $company)->where('employee_id', $employee->id)->where('type_id', $type->getKey())
            ->where('kind', LeaveEntry::ACCRUAL)->where('source', $source)->exists();
        if ($done) {
            return null;
        }

        $summary = app(AttendanceSummary::class);
        $atWork = 0;
        for ($month = $start->max($employee->joinedOn->startOfMonth()); $month->lessThanOrEqualTo($lastMonth); $month = $month->addMonth()) {
            $from = $month->max($employee->joinedOn);
            $to = $month->endOfMonth()->startOfDay();
            $atWork += $summary->forPeriod($company, [$employee->id], $from, $to)[$employee->id]['attended_days'] ?? 0;
        }

        return [$source, LeavePolicy::earnedTarget($atWork, (int) $type->worked_days_per_day)];
    }

    private function line(Organization $company, EmployeeRecord $employee, LeaveType $type, int $year, string $kind, int $halves, string $source, bool $evenIfNone = false): int
    {
        if ($halves <= 0 && ! $evenIfNone) {
            return 0;
        }

        return $this->balances->write($company, $employee->id, $type, $year, $kind, $halves, $source) === null ? 0 : 1;
    }
}
