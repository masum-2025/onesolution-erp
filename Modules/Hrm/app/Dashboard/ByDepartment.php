<?php

namespace Modules\Hrm\Dashboard;

use App\Platform\Dashboard\Contracts\DashboardWidget;
use App\Platform\Dashboard\WidgetData;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Modules\Hrm\Enums\EmployeeStatus;

/** Employed people per department: the six largest, the rest together, and those in no department. */
final class ByDepartment implements DashboardWidget
{
    use ReadsEmployees;

    private const SHOWN = 6;

    public function data(CurrentContext $context): array
    {
        // Counted here rather than in SQL: no raw SQL, and the same on MySQL and PostgreSQL.
        $perUnit = $this->employees($context)
            ->where('status', '!=', EmployeeStatus::Exited->value)
            ->pluck('organization_id')
            ->countBy();

        $departments = Organization::query()
            ->whereKey($perUnit->keys()->all())
            ->where('type', OrganizationType::Department->value)
            ->get()
            ->keyBy(fn (Organization $unit) => $unit->getKey());

        $counts = $perUnit->filter(fn (int $people, $id) => $departments->has($id))->sortDesc();
        $outside = $perUnit->reject(fn (int $people, $id) => $departments->has($id))->sum();

        // The same name in two branches is told apart by the branch.
        $names = $departments->map(fn (Organization $unit) => $unit->displayName());
        $twice = $names->countBy()->filter(fn (int $times) => $times > 1);
        $label = function (Organization $unit) use ($names, $twice) {
            $name = $names[$unit->getKey()];

            return $twice->has($name) && $unit->parent_id !== null
                ? $name.' · '.Organization::query()->find($unit->parent_id)?->displayName()
                : $name;
        };

        $items = $counts->take(self::SHOWN)->map(fn (int $people, $id) => ['label' => $label($departments[$id]), 'value' => $people])->values()->all();
        if ($counts->count() > self::SHOWN) {
            $items[] = ['label' => __('hrm::dashboard.other_departments'), 'value' => $counts->skip(self::SHOWN)->sum()];
        }
        if ($outside > 0) {
            $items[] = ['label' => __('hrm::dashboard.no_department'), 'value' => $outside];
        }

        return WidgetData::bars($items);
    }
}
