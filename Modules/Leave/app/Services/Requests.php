<?php

namespace Modules\Leave\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Hrm\Directory\EmployeeRecord;
use Modules\Leave\Exceptions\LeaveException;
use Modules\Leave\Models\LeaveApproval;
use Modules\Leave\Models\LeaveEntry;
use Modules\Leave\Models\LeaveRequest;
use Modules\Leave\Models\LeaveType;

/**
 * Asking for leave and deciding it.
 *
 * - A request covers days of one leave year, at most leave.backdate_days
 *   back, not overlapping another waiting or approved one. Weekends and
 *   holidays are not counted unless the type counts them.
 * - Paid types need the balance (less what is already waiting); beyond it
 *   the request is refused, or the rest is without pay
 *   (leave.beyond_balance). Types without pay need no balance.
 * - leave.approval_steps people decide, each a different person, never the
 *   employee nor whoever asked. The first step is the employee's manager
 *   when the manager has a login that may approve (people with leave.manage
 *   may always decide). At the last approval the paid days are taken from
 *   the balance; cancelling approved leave gives them back.
 * - Every step is audited; approvers and the employee are told.
 */
class Requests
{
    public function __construct(
        private Place $place,
        private Balances $balances,
        private WorkCalendar $calendar,
        private EmployeeDirectory $directory,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
        private Recipients $recipients,
        private Notifier $notifier,
    ) {}

    /**
     * @param  array{type_id: string, from_date: string, to_date: string, from_half?: bool, to_half?: bool, reason: string, op_id?: string|null}  $data
     */
    public function ask(Organization $company, EmployeeRecord $employee, array $data, User $actor): LeaveRequest
    {
        if (! empty($data['op_id'])) {
            $same = $this->place->query(LeaveRequest::class, $company)->where('op_id', $data['op_id'])->first();
            if ($same !== null) {
                return $same;
            }
        }

        $type = $this->place->query(LeaveType::class, $company)->whereKey($data['type_id'])->first() ?? throw LeaveException::typeNotFound();
        if (! $type->is_active || ! $type->openTo($employee->gender)) {
            throw LeaveException::typeClosed();
        }
        $from = CarbonImmutable::parse($data['from_date'], 'UTC');
        $to = CarbonImmutable::parse($data['to_date'], 'UTC');
        $fromHalf = (bool) ($data['from_half'] ?? false);
        $toHalf = (bool) ($data['to_half'] ?? false);
        if (($fromHalf || $toHalf) && ! $type->half_day) {
            throw ValidationException::withMessages(['from_half' => __('leave::leave.validation.no_half_days')]);
        }
        if ($from->equalTo($to) && $fromHalf && $toHalf) {
            throw ValidationException::withMessages(['to_half' => __('leave::leave.validation.one_half_only')]);
        }
        if (! $employee->employedOn($from) || ! $employee->employedOn($to)) {
            throw LeaveException::notEmployed();
        }

        $unit = Organization::query()->findOrFail($employee->unitId);
        $context = $this->contexts->forOrganization($unit);
        $back = (int) $this->rules->get('leave.backdate_days', $context);
        if ($from->lessThan($this->place->today($company)->subDays($back))) {
            throw ValidationException::withMessages(['from_date' => __('leave::leave.validation.too_far_back', ['days' => $back])]);
        }
        $startMonth = $this->balances->startMonth($unit);
        $year = LeavePolicy::yearOf($from, $startMonth);
        if (LeavePolicy::yearOf($to, $startMonth) !== $year) {
            throw LeaveException::crossesYear();
        }

        $halves = array_sum(array_column(LeavePolicy::days($from, $to, $fromHalf, $toHalf, $this->calendar->offDays($company, $unit), $type->counts_days_off), 'halves'));
        if ($halves === 0) {
            throw LeaveException::noWorkingDays();
        }

        return $this->place->transaction($company, function () use ($company, $employee, $type, $year, $from, $to, $fromHalf, $toHalf, $halves, $data, $actor, $context) {
            $overlap = $this->place->query(LeaveRequest::class, $company)->where('employee_id', $employee->id)
                ->whereIn('status', [LeaveRequest::PENDING, LeaveRequest::APPROVED])
                ->where('from_date', '<=', $to->toDateString())->where('to_date', '>=', $from->toDateString())->lockForUpdate()->exists();
            if ($overlap) {
                throw LeaveException::overlaps();
            }

            $unpaid = $type->paid ? 0 : $halves;
            if ($type->paid) {
                $available = $this->balances->available($company, $employee->id, $type->getKey(), $year);
                if ($halves > $available) {
                    if ($this->rules->get('leave.beyond_balance', $context) !== 'unpaid') {
                        throw LeaveException::notEnough($available);
                    }
                    $unpaid = $halves - max(0, $available);
                }
            }

            $request = new LeaveRequest;
            $request->fill([
                'organization_id' => $company->getKey(), 'unit_id' => $employee->unitId, 'employee_id' => $employee->id, 'type_id' => $type->getKey(),
                'leave_year' => $year, 'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(), 'from_half' => $fromHalf, 'to_half' => $toHalf,
                'halves' => $halves, 'unpaid_halves' => $unpaid, 'reason' => $data['reason'], 'status' => LeaveRequest::PENDING,
                'steps_needed' => max(1, min(3, (int) $this->rules->get('leave.approval_steps', $context))), 'steps_done' => 0,
                'requested_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null, 'version' => 1,
            ])->save();
            $this->audit->record('leave.requested', $request, new: $this->values($request), reason: $data['reason'], actor: $actor, organizationId: $company->getKey());
            $this->tell('leave.requested', $company, $request, $type, $employee, $this->recipients->holding('leave.approve', Organization::query()->findOrFail($employee->unitId))
                ->reject(fn (User $user) => $user->getKey() === $employee->userId || $user->getKey() === $actor->getKey()));

            return $request;
        });
    }

    public function approve(Organization $company, LeaveRequest $request, int $baseVersion, ?string $note, User $actor): LeaveRequest
    {
        return $this->place->transaction($company, function () use ($company, $request, $baseVersion, $note, $actor) {
            [$request, $employee] = $this->pending($company, $request, $baseVersion, $actor);
            $step = $request->steps_done + 1;
            $this->record($company, $request, $step, 'approved', $note, $actor);
            $done = $step >= $request->steps_needed;
            $request->forceFill([
                'steps_done' => $step, 'version' => $request->version + 1,
                ...($done ? ['status' => LeaveRequest::APPROVED, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_note' => $note] : []),
            ])->save();

            $type = $this->typeOf($company, $request);
            if ($done) {
                $paid = $request->halves - $request->unpaid_halves;
                if ($paid > 0) {
                    $this->balances->write($company, $request->employee_id, $type, $request->leave_year, LeaveEntry::TAKEN, -$paid, 'request-'.$request->getKey(), $request->getKey(), null, $actor);
                }
            }
            $this->audit->record($done ? 'leave.approved' : 'leave.step_approved', $request, new: [...$this->values($request), 'step' => $step], reason: $note, actor: $actor, organizationId: $company->getKey());
            if ($done) {
                $this->tell('leave.decided', $company, $request, $type, $employee, $this->userOf($employee), $note);
            }

            return $request;
        });
    }

    public function reject(Organization $company, LeaveRequest $request, int $baseVersion, ?string $note, User $actor): LeaveRequest
    {
        return $this->place->transaction($company, function () use ($company, $request, $baseVersion, $note, $actor) {
            [$request, $employee] = $this->pending($company, $request, $baseVersion, $actor);
            $this->record($company, $request, $request->steps_done + 1, 'rejected', $note, $actor);
            $request->forceFill(['status' => LeaveRequest::REJECTED, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_note' => $note, 'version' => $request->version + 1])->save();
            $this->audit->record('leave.rejected', $request, new: $this->values($request), reason: $note, actor: $actor, organizationId: $company->getKey());
            $this->tell('leave.decided', $company, $request, $this->typeOf($company, $request), $employee, $this->userOf($employee), $note);

            return $request;
        });
    }

    /**
     * Withdrawn by the employee (or whoever asked) while waiting or before
     * it starts; by people with leave.manage at any time. Approved paid days
     * go back to the balance.
     */
    public function cancel(Organization $company, LeaveRequest $request, int $baseVersion, string $reason, User $actor): LeaveRequest
    {
        return $this->place->transaction($company, function () use ($company, $request, $baseVersion, $reason, $actor) {
            /** @var LeaveRequest $fresh */
            $fresh = $this->place->query(LeaveRequest::class, $company)->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            if ($fresh->version !== $baseVersion) {
                throw LeaveException::versionConflict(['version' => $fresh->version, 'status' => $fresh->status]);
            }
            $employee = $this->directory->find($company, $fresh->employee_id) ?? throw LeaveException::employeeNotFound();
            $mine = $fresh->requested_by === $actor->getKey() || $employee->userId === $actor->getKey();
            $manager = Gate::forUser($actor)->allows('leave.manage', Organization::query()->findOrFail($fresh->unit_id));
            $open = $fresh->status === LeaveRequest::PENDING
                || ($fresh->status === LeaveRequest::APPROVED && $fresh->from_date->greaterThan($this->place->today($company)));
            if (! in_array($fresh->status, [LeaveRequest::PENDING, LeaveRequest::APPROVED], true) || ! ($manager || ($mine && $open))) {
                throw LeaveException::cannotCancel();
            }

            if ($fresh->status === LeaveRequest::APPROVED && $fresh->halves > $fresh->unpaid_halves) {
                $this->balances->write($company, $fresh->employee_id, $this->typeOf($company, $fresh), $fresh->leave_year, LeaveEntry::RETURNED,
                    $fresh->halves - $fresh->unpaid_halves, 'request-'.$fresh->getKey(), $fresh->getKey(), $reason, $actor);
            }
            $fresh->forceFill(['status' => LeaveRequest::CANCELLED, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_note' => $reason, 'version' => $fresh->version + 1])->save();
            $this->audit->record('leave.cancelled', $fresh, new: $this->values($fresh), reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $fresh;
        });
    }

    /** Whether this person may decide the request's next step (the list shows it; the server checks it again). */
    public function canDecide(Organization $company, LeaveRequest $request, User $actor, ?EmployeeRecord $employee = null): bool
    {
        if ($request->status !== LeaveRequest::PENDING) {
            return false;
        }
        $employee ??= $this->directory->find($company, $request->employee_id);
        if ($employee === null || $request->requested_by === $actor->getKey() || $employee->userId === $actor->getKey()) {
            return false;
        }
        $unit = Organization::query()->find($request->unit_id);
        if ($unit === null || ! Gate::forUser($actor)->allows('leave.approve', $unit)) {
            return false;
        }
        $already = $this->place->query(LeaveApproval::class, $company)->where('request_id', $request->getKey())->where('user_id', $actor->getKey())->exists();
        if ($already) {
            return false;
        }
        if ($request->steps_done === 0 && ! Gate::forUser($actor)->allows('leave.manage', $unit)) {
            $managerUser = $this->managerUser($company, $employee, $unit);

            return $managerUser === null || $managerUser === $actor->getKey();
        }

        return true;
    }

    /**
     * @return array{0: LeaveRequest, 1: EmployeeRecord}
     */
    private function pending(Organization $company, LeaveRequest $request, int $baseVersion, User $actor): array
    {
        /** @var LeaveRequest $fresh */
        $fresh = $this->place->query(LeaveRequest::class, $company)->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw LeaveException::versionConflict(['version' => $fresh->version, 'status' => $fresh->status]);
        }
        if ($fresh->status !== LeaveRequest::PENDING) {
            throw LeaveException::decided();
        }
        $employee = $this->directory->find($company, $fresh->employee_id) ?? throw LeaveException::employeeNotFound();
        if ($fresh->requested_by === $actor->getKey() || $employee->userId === $actor->getKey()) {
            throw LeaveException::ownRequest();
        }
        if (! $this->canDecide($company, $fresh, $actor, $employee)) {
            throw LeaveException::notYourStep();
        }

        return [$fresh, $employee];
    }

    /** The manager's login, when the manager has one that may approve leave at the unit. */
    private function managerUser(Organization $company, EmployeeRecord $employee, Organization $unit): ?string
    {
        $manager = $employee->managerId === null ? null : $this->directory->find($company, $employee->managerId);
        if ($manager?->userId === null) {
            return null;
        }
        // Gates answer for the signed-in person's context; for someone else ask who holds the permission there.
        $holds = $this->recipients->holding('leave.approve', $unit)->contains(fn (User $user) => $user->getKey() === $manager->userId);

        return $holds ? $manager->userId : null;
    }

    private function record(Organization $company, LeaveRequest $request, int $step, string $decision, ?string $note, User $actor): void
    {
        $approval = new LeaveApproval;
        $approval->fill(['organization_id' => $company->getKey(), 'request_id' => $request->getKey(), 'step' => $step, 'user_id' => $actor->getKey(), 'decision' => $decision, 'note' => $note])->save();
    }

    private function typeOf(Organization $company, LeaveRequest $request): LeaveType
    {
        return $this->place->query(LeaveType::class, $company)->whereKey($request->type_id)->firstOrFail();
    }

    /**
     * @return Collection<int, User>
     */
    private function userOf(EmployeeRecord $employee): Collection
    {
        return $employee->userId === null ? collect() : User::query()->whereKey($employee->userId)->get();
    }

    /**
     * @param  Collection<int, User>  $to
     */
    private function tell(string $key, Organization $company, LeaveRequest $request, LeaveType $type, EmployeeRecord $employee, Collection $to, ?string $note = null): void
    {
        if ($to->isEmpty()) {
            return;
        }
        $this->notifier->notify(
            $key,
            $to->values(),
            fn (string $locale) => [
                'organization' => $company->displayName($locale),
                'employee' => $employee->name,
                'type' => $type->textIn('name', $locale),
                'dates' => $request->from_date->locale($locale)->isoFormat('LL').($request->to_date->equalTo($request->from_date) ? '' : ' – '.$request->to_date->locale($locale)->isoFormat('LL')),
                'days' => intdiv($request->halves, 2).($request->halves % 2 === 0 ? '' : '.5'),
                'decision' => __("leave::leave.status.{$request->status}", [], $locale),
                'note' => (string) $note,
            ],
            $company->partner,
            $company,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function values(LeaveRequest $request): array
    {
        return [
            'employee_id' => $request->employee_id, 'type_id' => $request->type_id, 'from_date' => $request->from_date->toDateString(),
            'to_date' => $request->to_date->toDateString(), 'halves' => $request->halves, 'unpaid_halves' => $request->unpaid_halves, 'status' => $request->status,
        ];
    }
}
