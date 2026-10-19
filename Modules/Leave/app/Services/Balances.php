<?php

namespace Modules\Leave\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Hrm\Directory\EmployeeRecord;
use Modules\Leave\Models\LeaveEntry;
use Modules\Leave\Models\LeaveRequest;
use Modules\Leave\Models\LeaveType;

/**
 * Leave balances from the append-only ledger (half days): what a leave
 * year gave (accrual, carried, adjusted, returned) less what was taken;
 * requests still waiting are held back from what is available. HR may
 * change a balance by hand with a reason (audited, an entry of its own,
 * never an edit).
 */
class Balances
{
    public function __construct(
        private Place $place,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /** The leave year a day falls in, at the employee's unit. */
    public function yearOf(Organization $unit, CarbonImmutable $day): int
    {
        return LeavePolicy::yearOf($day, $this->startMonth($unit));
    }

    public function startMonth(Organization $unit): int
    {
        return (int) $this->rules->get('leave.year_start_month', $this->contexts->forOrganization($unit));
    }

    public function balance(Organization $company, string $employeeId, string $typeId, int $year): int
    {
        return (int) $this->place->query(LeaveEntry::class, $company)->where('employee_id', $employeeId)
            ->where('type_id', $typeId)->where('leave_year', $year)->sum('halves');
    }

    /** Paid half days asked for and not decided yet. */
    public function waiting(Organization $company, string $employeeId, string $typeId, int $year, ?string $except = null): int
    {
        return (int) $this->place->query(LeaveRequest::class, $company)->where('employee_id', $employeeId)
            ->where('type_id', $typeId)->where('leave_year', $year)->where('status', LeaveRequest::PENDING)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->get(['halves', 'unpaid_halves'])->sum(fn (LeaveRequest $request) => $request->halves - $request->unpaid_halves);
    }

    public function available(Organization $company, string $employeeId, string $typeId, int $year): int
    {
        return $this->balance($company, $employeeId, $typeId, $year) - $this->waiting($company, $employeeId, $typeId, $year);
    }

    /**
     * Each type someone may take, with the year's figures (half days).
     *
     * @return list<array{type: LeaveType, given: int, taken: int, balance: int, waiting: int, available: int}>
     */
    public function forEmployee(Organization $company, EmployeeRecord $employee, int $year): array
    {
        $types = $this->place->query(LeaveType::class, $company)->where('is_active', true)->orderBy('sort_order')->orderBy('code')->get()
            ->filter(fn (LeaveType $type) => $type->openTo($employee->gender));
        $entries = $this->place->query(LeaveEntry::class, $company)->where('employee_id', $employee->id)->where('leave_year', $year)
            ->get(['type_id', 'kind', 'halves'])->groupBy('type_id');

        return $types->map(function (LeaveType $type) use ($company, $employee, $year, $entries) {
            $rows = $entries->get($type->getKey(), collect());
            $taken = (int) -$rows->where('kind', LeaveEntry::TAKEN)->sum('halves');
            $balance = (int) $rows->sum('halves');
            $waiting = $this->waiting($company, $employee->id, $type->getKey(), $year);

            return ['type' => $type, 'given' => $balance + $taken, 'taken' => $taken, 'balance' => $balance, 'waiting' => $waiting, 'available' => $balance - $waiting];
        })->values()->all();
    }

    /** Write a ledger line once per source (accruals, carry, requests); null when it was already there. */
    public function write(Organization $company, string $employeeId, LeaveType $type, int $year, string $kind, int $halves, string $source, ?string $requestId = null, ?string $note = null, ?User $actor = null): ?LeaveEntry
    {
        $exists = $this->place->query(LeaveEntry::class, $company)->where('employee_id', $employeeId)->where('type_id', $type->getKey())
            ->where('kind', $kind)->where('source', $source)->exists();
        if ($exists) {
            return null;
        }
        $entry = new LeaveEntry;
        $entry->fill([
            'organization_id' => $company->getKey(), 'employee_id' => $employeeId, 'type_id' => $type->getKey(), 'leave_year' => $year,
            'kind' => $kind, 'halves' => $halves, 'source' => $source, 'request_id' => $requestId, 'note' => $note, 'created_by' => $actor?->getKey(),
        ])->save();

        return $entry;
    }

    /** HR changes a balance by hand (positive or negative half days), with a reason. */
    public function adjust(Organization $company, EmployeeRecord $employee, LeaveType $type, int $year, int $halves, string $note, User $actor): LeaveEntry
    {
        return $this->place->transaction($company, function () use ($company, $employee, $type, $year, $halves, $note, $actor) {
            $entry = $this->write($company, $employee->id, $type, $year, LeaveEntry::ADJUSTED, $halves, 'adjust-'.Str::ulid(), null, $note, $actor);
            $this->audit->record('leave.balance_adjusted', $entry, new: [
                'employee_id' => $employee->id, 'type' => $type->code, 'leave_year' => $year, 'halves' => $halves,
            ], reason: $note, actor: $actor, organizationId: $company->getKey());

            return $entry;
        });
    }
}
