<?php

namespace Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Hrm\Enums\EmployeeStatus;
use Modules\Hrm\Http\Controllers\Concerns\FindsHrmRecords;
use Modules\Hrm\Http\EmployeePresenter;
use Modules\Hrm\Models\Employee;

/**
 * The org chart, one level at a time (fast on slow networks and in large
 * companies): the people at the top, or the direct reports of one person.
 * Employed people only, within the units the reader may see; no sensitive
 * details (the list fields only).
 */
class OrgChartController extends Controller
{
    use FindsHrmRecords;

    /** Most people returned for one level; the rest are counted. */
    private const PAGE = 200;

    public function __construct(private EmployeePresenter $presenter) {}

    public function __invoke(Request $request, string $organization): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('hrm.view', $organization);
        $request->validate(['manager_id' => ['nullable', 'string', 'size:26']]);

        $units = $this->subtreeIds($organization);
        $employed = fn () => Employee::query()->whereIn('organization_id', $units)->where('status', '!=', EmployeeStatus::Exited->value);

        $manager = $request->filled('manager_id') ? $this->employeeIn($organization, $request->string('manager_id')->toString()) : null;

        $level = $employed()
            ->when(
                $manager !== null,
                fn (Builder $query) => $query->where('manager_id', $manager->getKey()),
                // The top: nobody above them here (no manager, or one who left or sits outside these units).
                fn (Builder $query) => $query->where(fn (Builder $top) => $top
                    ->whereNull('manager_id')
                    ->orWhereNotIn('manager_id', $employed()->select('id'))),
            );

        $total = (clone $level)->count();
        $people = $level->with('position')
            ->withCount(['reports' => fn (Builder $query) => $query->whereIn('organization_id', $units)->where('status', '!=', EmployeeStatus::Exited->value)])
            ->orderBy('full_name')
            ->limit(self::PAGE)
            ->get();

        return response()->json([
            'data' => $people->map(fn (Employee $employee) => [
                ...$this->presenter->listItem($employee),
                'reports_count' => (int) $employee->reports_count,
            ])->values(),
            'manager' => $manager === null ? null : ['id' => $manager->getKey(), 'full_name' => $manager->full_name],
            'meta' => ['total' => $total, 'shown' => $people->count()],
        ]);
    }
}
