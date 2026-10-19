<?php

namespace Modules\Leave\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Validation\ValidationException;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Hrm\Directory\EmployeeRecord;
use Modules\Leave\Exceptions\LeaveException;
use Modules\Leave\Models\LeaveEncashment;
use Modules\Leave\Models\LeaveEntry;
use Modules\Leave\Models\LeaveType;

/**
 * Unused leave paid out. HR (leave.manage) asks for half days of a kind
 * that may be paid out, at most what is left (requests and other pay-outs
 * waiting held back). It is money, so someone else with leave.approve
 * decides, never whoever asked nor the employee. Approved, the days leave
 * the balance (ledger kind "encashed") and Payroll pays them in the run
 * whose days include the approval day (pay_on), at a day of pay as its
 * rules say. Every step is audited.
 */
class Encashments
{
    public function __construct(
        private Place $place,
        private Balances $balances,
        private EmployeeDirectory $directory,
        private AuditLogger $audit,
    ) {}

    public function ask(Organization $company, EmployeeRecord $employee, LeaveType $type, int $year, int $halves, string $reason, User $actor): LeaveEncashment
    {
        if (! $type->encashable || ! $type->paid) {
            throw LeaveException::notEncashable();
        }
        if ($halves <= 0) {
            throw ValidationException::withMessages(['halves' => __('leave::leave.validation.days_needed')]);
        }

        return $this->place->transaction($company, function () use ($company, $employee, $type, $year, $halves, $reason, $actor) {
            $available = $this->balances->available($company, $employee->id, $type->getKey(), $year);
            if ($halves > $available) {
                throw LeaveException::notEnough($available);
            }
            $encashment = new LeaveEncashment;
            $encashment->fill([
                'organization_id' => $company->getKey(), 'unit_id' => $employee->unitId, 'employee_id' => $employee->id, 'type_id' => $type->getKey(),
                'leave_year' => $year, 'halves' => $halves, 'reason' => $reason, 'status' => LeaveEncashment::PENDING,
                'requested_by' => $actor->getKey(), 'version' => 1,
            ])->save();
            $this->audit->record('leave.encashment_asked', $encashment, new: $this->values($encashment), reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $encashment;
        });
    }

    public function approve(Organization $company, LeaveEncashment $encashment, int $baseVersion, ?string $note, User $actor): LeaveEncashment
    {
        return $this->place->transaction($company, function () use ($company, $encashment, $baseVersion, $note, $actor) {
            $fresh = $this->pending($company, $encashment, $baseVersion, $actor);
            $type = $this->place->query(LeaveType::class, $company)->whereKey($fresh->type_id)->firstOrFail();
            // What is left now, without this pay-out (it is part of "waiting" until decided).
            $left = $this->balances->balance($company, $fresh->employee_id, $type->getKey(), $fresh->leave_year);
            if ($fresh->halves > $left) {
                throw LeaveException::notEnough($left);
            }
            $this->balances->write($company, $fresh->employee_id, $type, $fresh->leave_year, LeaveEntry::ENCASHED, -$fresh->halves, 'encash-'.$fresh->getKey(), null, $note, $actor);
            $fresh->forceFill([
                'status' => LeaveEncashment::APPROVED, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_note' => $note,
                'pay_on' => $this->place->today($company)->toDateString(), 'version' => $fresh->version + 1,
            ])->save();
            $this->audit->record('leave.encashment_approved', $fresh, new: $this->values($fresh), reason: $note, actor: $actor, organizationId: $company->getKey());

            return $fresh;
        });
    }

    public function reject(Organization $company, LeaveEncashment $encashment, int $baseVersion, ?string $note, User $actor): LeaveEncashment
    {
        return $this->place->transaction($company, function () use ($company, $encashment, $baseVersion, $note, $actor) {
            $fresh = $this->pending($company, $encashment, $baseVersion, $actor);
            $fresh->forceFill(['status' => LeaveEncashment::REJECTED, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_note' => $note, 'version' => $fresh->version + 1])->save();
            $this->audit->record('leave.encashment_rejected', $fresh, new: $this->values($fresh), reason: $note, actor: $actor, organizationId: $company->getKey());

            return $fresh;
        });
    }

    /** Whether this person may decide it (the list shows it; the server checks it again). */
    public function canDecide(Organization $company, LeaveEncashment $encashment, User $actor, ?EmployeeRecord $employee = null): bool
    {
        $employee ??= $this->directory->find($company, $encashment->employee_id);

        return $encashment->status === LeaveEncashment::PENDING && $encashment->requested_by !== $actor->getKey() && $employee?->userId !== $actor->getKey();
    }

    private function pending(Organization $company, LeaveEncashment $encashment, int $baseVersion, User $actor): LeaveEncashment
    {
        /** @var LeaveEncashment $fresh */
        $fresh = $this->place->query(LeaveEncashment::class, $company)->whereKey($encashment->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw LeaveException::versionConflict(['version' => $fresh->version, 'status' => $fresh->status]);
        }
        if ($fresh->status !== LeaveEncashment::PENDING) {
            throw LeaveException::decided();
        }
        if (! $this->canDecide($company, $fresh, $actor)) {
            throw LeaveException::ownEncashment();
        }

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(LeaveEncashment $encashment): array
    {
        return [
            'employee_id' => $encashment->employee_id, 'type_id' => $encashment->type_id, 'leave_year' => $encashment->leave_year,
            'halves' => $encashment->halves, 'status' => $encashment->status, 'pay_on' => $encashment->pay_on?->toDateString(),
        ];
    }
}
