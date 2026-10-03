<?php

namespace Modules\Hrm\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Hrm\Models\Employee;

/**
 * Who reports to whom. A manager change must never close a loop (A reports
 * to B, B to A) and a reporting line may only be so long (rule
 * hrm.max_reporting_depth), so the org chart is always a tree.
 */
final class ReportingLines
{
    /**
     * What is wrong with $manager becoming the manager of $employee, if anything.
     * Walks up from the new manager; reaching $employee means a loop.
     *
     * @param  Builder<Employee>  $companyEmployees  Every employee of the company (existence checks only).
     * @return array{problem: 'loop'|'too_deep', chain: list<string>}|null chain: the line as people read it.
     */
    public function problem(Builder $companyEmployees, ?Employee $employee, Employee $manager, int $maxDepth): ?array
    {
        $chain = [$manager->full_name];
        $current = $manager;
        $seen = [$manager->getKey() => true];

        // A new hire has nobody below them yet, so only the line's length can be wrong.
        for ($depth = 1; $current->manager_id !== null; $depth++) {
            if ($employee !== null && $current->manager_id === $employee->getKey()) {
                // Read as "A reports to B, who reports to ... A".
                return ['problem' => 'loop', 'chain' => [$employee->full_name, ...$chain, $employee->full_name]];
            }
            if ($depth >= $maxDepth || isset($seen[$current->manager_id])) {
                return ['problem' => 'too_deep', 'chain' => $chain];
            }

            $current = (clone $companyEmployees)->whereKey($current->manager_id)->first(['id', 'full_name', 'manager_id']);
            if ($current === null) {
                return null;
            }
            $seen[$current->getKey()] = true;
            $chain[] = $current->full_name;
        }

        return null;
    }
}
