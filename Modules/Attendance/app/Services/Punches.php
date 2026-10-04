<?php

namespace Modules\Attendance\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Attendance\Exceptions\AttendanceException;
use Modules\Attendance\Models\Punch;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Hrm\Directory\EmployeeRecord;

/**
 * Check-ins and check-outs. An employee punches for themselves (rule
 * attendance.self_punch; their login linked to them in HRM; from a
 * workplace when the unit asks for a location check), online or offline,
 * or HR writes one by hand (audited), or an attendance machine's file
 * brings them in. A repeated op_id returns the punch already kept,
 * and a second tap within a minute counts once. Punches never change: a
 * mistaken one is voided with a reason. Each one works out its day again.
 */
class Punches
{
    /** A second tap within this many seconds is the same punch. */
    private const SAME_TAP_SECONDS = 60;

    public function __construct(
        private Workplace $workplace,
        private Days $days,
        private EmployeeDirectory $directory,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
        private Locations $locations,
    ) {}

    /** The employee a login belongs to in this company (HR links them in HRM). */
    public function employeeOf(Organization $company, User $user): EmployeeRecord
    {
        return $this->directory->forUser($company, $user) ?? throw AttendanceException::notLinked();
    }

    /**
     * The person checks in now. Where the unit asks for a location check,
     * the phone's fix must be inside one of its workplaces.
     *
     * @param  array{latitude_micro: int, longitude_micro: int, accuracy_m: int}|null  $fix
     */
    public function self(Organization $company, User $user, ?string $opId, ?array $fix = null): Punch
    {
        $employee = $this->employeeOf($company, $user);
        $where = $this->checkSelf($company, $employee, $fix);

        return $this->write($company, $employee, CarbonImmutable::now(), 'self', $opId, null, $user, sameTap: true, where: $where);
    }

    /**
     * A check-in made while the phone was offline, at the time the phone
     * recorded (no older than attendance.offline_max_age_hours, never in the
     * future), with the same location check.
     *
     * @param  array{latitude_micro: int, longitude_micro: int, accuracy_m: int}|null  $fix
     */
    public function offline(Organization $company, User $user, CarbonImmutable $madeAt, string $opId, ?array $fix): Punch
    {
        $employee = $this->employeeOf($company, $user);
        $hours = (int) $this->rules->get('attendance.offline_max_age_hours', $this->contexts->forOrganization(Organization::query()->findOrFail($employee->unitId)));
        if ($madeAt->lessThan(CarbonImmutable::now()->subHours($hours))) {
            throw AttendanceException::offlineTooOld($hours);
        }
        if ($madeAt->greaterThan(CarbonImmutable::now()->addMinutes(5))) {
            throw AttendanceException::offlineFuture();
        }
        $where = $this->checkSelf($company, $employee, $fix);

        return $this->write($company, $employee, $madeAt, 'offline', $opId, null, $user, sameTap: true, where: $where);
    }

    /** A punch from an attendance machine's file (see DeviceImports). */
    public function fromDevice(Organization $company, EmployeeRecord $employee, CarbonImmutable $at, string $opId, User $actor): Punch
    {
        return $this->write($company, $employee, $at, 'device', $opId, null, $actor, sameTap: false, recompute: false);
    }

    /**
     * Self check-in allowed (attendance.self_punch), and where the unit asks
     * for it, the location check passed.
     *
     * @param  array{latitude_micro: int, longitude_micro: int, accuracy_m: int}|null  $fix
     * @return array<string, int|string>
     */
    private function checkSelf(Organization $company, EmployeeRecord $employee, ?array $fix): array
    {
        $unit = Organization::query()->findOrFail($employee->unitId);
        if (! (bool) $this->rules->get('attendance.self_punch', $this->contexts->forOrganization($unit))) {
            throw AttendanceException::selfPunchOff();
        }

        // Where a person is, is asked for only when the unit needs it.
        return $this->locations->required($unit) ? $this->locations->check($company, $unit, $fix) : [];
    }

    public function manual(Organization $company, EmployeeRecord $employee, CarbonImmutable $at, ?string $note, ?string $opId, User $actor): Punch
    {
        return $this->write($company, $employee, $at, 'manual', $opId, $note, $actor, sameTap: false);
    }

    /** Kept by an approved correction (see Corrections). */
    public function fromCorrection(Organization $company, EmployeeRecord $employee, CarbonImmutable $at, string $opId, User $actor): Punch
    {
        return $this->write($company, $employee, $at, 'correction', $opId, null, $actor, sameTap: false);
    }

    public function void(Organization $company, Punch $punch, string $reason, User $actor): Punch
    {
        return $this->workplace->transaction($company, function () use ($company, $punch, $reason, $actor) {
            /** @var Punch $punch */
            $punch = $this->workplace->query(Punch::class, $company)->whereKey($punch->getKey())->lockForUpdate()->firstOrFail();
            if ($punch->voided_at !== null) {
                throw AttendanceException::punchVoided();
            }

            $punch->forceFill(['voided_at' => now(), 'voided_by' => $actor->getKey(), 'void_reason' => $reason])->save();
            $this->audit->record('attendance.punch_voided', $punch, new: ['employee_id' => $punch->employee_id, 'punched_at' => $punch->punched_at->toIso8601String()],
                reason: $reason, actor: $actor, organizationId: $company->getKey());

            if ($employee = $this->directory->find($company, $punch->employee_id)) {
                $this->days->touch($company, $employee, $punch->punched_at);
            }

            return $punch;
        });
    }

    /**
     * @param  array<string, int|string>  $where  Location fields (see Locations::check).
     */
    private function write(Organization $company, EmployeeRecord $employee, CarbonImmutable $at, string $source, ?string $opId, ?string $note, User $actor, bool $sameTap, array $where = [], bool $recompute = true): Punch
    {
        if (! $employee->employedOn($this->workplace->dayOf($company, $at))) {
            throw AttendanceException::notEmployed();
        }

        return $this->workplace->transaction($company, function () use ($company, $employee, $at, $source, $opId, $note, $actor, $sameTap, $where, $recompute) {
            $punches = fn () => $this->workplace->query(Punch::class, $company)->where('employee_id', $employee->id);
            if ($opId !== null && ($kept = $this->workplace->query(Punch::class, $company)->where('op_id', $opId)->first())) {
                return $kept;
            }
            if ($sameTap && ($kept = $punches()->whereNull('voided_at')->where('punched_at', '>', $at->subSeconds(self::SAME_TAP_SECONDS))->orderByDesc('punched_at')->first())) {
                return $kept;
            }

            $punch = new Punch;
            $punch->fill([
                'organization_id' => $company->getKey(), 'unit_id' => $employee->unitId, 'employee_id' => $employee->id,
                'punched_at' => $at->utc(), 'source' => $source, 'op_id' => $opId, 'note' => $note, 'created_by' => $actor->getKey(), ...$where,
            ])->save();
            if ($source === 'manual') {
                $this->audit->record('attendance.punch_written', $punch, new: ['employee_id' => $employee->id, 'punched_at' => $at->toIso8601String(), 'note' => $note],
                    actor: $actor, organizationId: $company->getKey());
            }
            // A machine file works its days out once, after all its lines (DeviceImports).
            if ($recompute) {
                $this->days->touch($company, $employee, $at);
            }

            return $punch;
        });
    }
}
