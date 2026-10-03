<?php

namespace Modules\Hrm\Dashboard;

use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Hrm\Enums\EmployeeStatus;
use Modules\Hrm\Models\Employee;

/**
 * What every HRM widget counts: the employees of the current organization and
 * the units below it that the person may see (as the employee list does), as
 * of today in its timezone.
 */
trait ReadsEmployees
{
    /**
     * @return Builder<Employee>
     */
    private function employees(CurrentContext $context): Builder
    {
        return Employee::query()->whereIn('organization_id', $this->unitIds($context));
    }

    /**
     * The organization and its units, as ids: business data may sit in a tenant
     * database that cannot join the organizations table. Which of them the
     * person may see, the models' tenant scope decides (BelongsToOrganization).
     *
     * @return list<string>
     */
    private function unitIds(CurrentContext $context): array
    {
        return Organization::query()->subtreeOf($context->organization())->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    private function today(CurrentContext $context): CarbonImmutable
    {
        return CarbonImmutable::now($context->timezone() ?? 'UTC')->startOfDay();
    }

    /** People employed on a day: joined by then and not yet left. */
    private function employedOn(CurrentContext $context, CarbonImmutable $day): int
    {
        return $this->employees($context)
            ->whereDate('joined_on', '<=', $day->toDateString())
            ->where(fn (Builder $query) => $query
                ->where('status', '!=', EmployeeStatus::Exited->value)
                ->orWhereDate('exits_on', '>', $day->toDateString()))
            ->count();
    }
}
