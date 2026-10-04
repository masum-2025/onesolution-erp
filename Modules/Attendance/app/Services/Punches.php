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
 * attendance.self_punch; their login linked to them in HRM), or HR writes
 * one by hand (audited). A repeated op_id returns the punch already kept,
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
    ) {}

    /** The employee a login belongs to in this company (HR links them in HRM). */
    public function employeeOf(Organization $company, User $user): EmployeeRecord
    {
        return $this->directory->forUser($company, $user) ?? throw AttendanceException::notLinked();
    }

    public function self(Organization $company, User $user, ?string $opId): Punch
    {
        $employee = $this->employeeOf($company, $user);
        $context = $this->contexts->forOrganization(Organization::query()->findOrFail($employee->unitId));
        if (! (bool) $this->rules->get('attendance.self_punch', $context)) {
            throw AttendanceException::selfPunchOff();
        }
        // Checking where a person is comes with ATT-3; until then a required location check blocks punching here.
        if ((bool) $this->rules->get('attendance.geo_fence_required', $context)) {
            throw AttendanceException::locationNeeded();
        }

        return $this->write($company, $employee, CarbonImmutable::now(), 'self', $opId, null, $user, sameTap: true);
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

    private function write(Organization $company, EmployeeRecord $employee, CarbonImmutable $at, string $source, ?string $opId, ?string $note, User $actor, bool $sameTap): Punch
    {
        if (! $employee->employedOn($this->workplace->dayOf($company, $at))) {
            throw AttendanceException::notEmployed();
        }

        return $this->workplace->transaction($company, function () use ($company, $employee, $at, $source, $opId, $note, $actor, $sameTap) {
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
                'punched_at' => $at->utc(), 'source' => $source, 'op_id' => $opId, 'note' => $note, 'created_by' => $actor->getKey(),
            ])->save();
            if ($source === 'manual') {
                $this->audit->record('attendance.punch_written', $punch, new: ['employee_id' => $employee->id, 'punched_at' => $at->toIso8601String(), 'note' => $note],
                    actor: $actor, organizationId: $company->getKey());
            }
            $this->days->touch($company, $employee, $at);

            return $punch;
        });
    }
}
