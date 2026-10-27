<?php

namespace Modules\Expenses\Services;

use App\Models\User;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Hrm\Directory\EmployeeRecord;

/**
 * Who decides a step of an employee's request (a claim, an advance): the
 * first step is the employee's HRM manager when they hold expenses.approve
 * where the employee works (else anyone holding it there), or anyone with
 * expenses.manage; the second is the finance people's (expenses.pay). Never
 * the employee, never whoever asked, never someone who decided a step of
 * it already.
 */
class Deciders
{
    public function __construct(private EmployeeDirectory $directory, private Recipients $recipients) {}

    /**
     * @param  list<string>  $decidedBy  Users who decided a step of it already.
     */
    public function canDecide(Organization $company, string $unitId, string $employeeId, string $requestedBy, int $stepsDone, array $decidedBy, User $actor): bool
    {
        $employee = $this->directory->find($company, $employeeId);
        if ($employee === null || $requestedBy === $actor->getKey() || $employee->userId === $actor->getKey() || in_array($actor->getKey(), $decidedBy, true)) {
            return false;
        }
        $unit = Organization::query()->find($unitId);
        if ($unit === null) {
            return false;
        }
        // The second step is the finance people's.
        if ($stepsDone >= 1) {
            return Gate::forUser($actor)->allows('expenses.pay', $unit);
        }
        if (! Gate::forUser($actor)->any(['expenses.approve', 'expenses.manage'], $unit)) {
            return false;
        }
        if (Gate::forUser($actor)->allows('expenses.manage', $unit)) {
            return true;
        }
        $manager = $this->managerUser($company, $employee, $unit);

        return $manager === null || $manager === $actor->getKey();
    }

    /**
     * The people who may decide the next step (for telling them).
     *
     * @return Collection<int, User>
     */
    public function approvers(Organization $company, string $unitId, string $employeeId, int $stepsDone): Collection
    {
        $unit = Organization::query()->find($unitId);
        if ($unit === null) {
            return collect();
        }
        if ($stepsDone >= 1) {
            return $this->recipients->holding('expenses.pay', $unit);
        }
        $employee = $this->directory->find($company, $employeeId);
        $manager = $employee === null ? null : $this->managerUser($company, $employee, $unit);

        return $manager === null ? $this->recipients->holding('expenses.approve', $unit) : User::query()->whereKey($manager)->get();
    }

    /** The employee's manager, when they hold expenses.approve where the employee works. */
    private function managerUser(Organization $company, EmployeeRecord $employee, Organization $unit): ?string
    {
        $manager = $employee->managerId === null ? null : $this->directory->find($company, $employee->managerId);
        if ($manager?->userId === null) {
            return null;
        }
        // Gates answer for the signed-in person's context; for someone else ask who holds the permission there.
        $holds = $this->recipients->holding('expenses.approve', $unit)->contains(fn (User $user) => $user->getKey() === $manager->userId);

        return $holds ? $manager->userId : null;
    }
}
