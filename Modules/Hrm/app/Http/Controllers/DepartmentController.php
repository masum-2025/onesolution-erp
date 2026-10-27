<?php

namespace Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Hrm\Enums\EmployeeStatus;
use Modules\Hrm\Http\Controllers\Concerns\FindsHrmRecords;
use Modules\Hrm\Models\Employee;

/**
 * Departments as people see them: per branch (or the company itself), the
 * departments under it and how many people work in each. Departments are
 * units of the organization tree; they are made, renamed and closed through
 * the platform's organization endpoints (organizations.manage), never here.
 */
class DepartmentController extends Controller
{
    use FindsHrmRecords;

    public function index(string $organization): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('hrm.view', $organization);
        $locale = app()->getLocale();

        /** @var Collection<int, Organization> $units */
        $units = Organization::query()->subtreeOf($organization)->orderBy('depth')->orderBy('path')->get();
        $departments = $units->filter(fn (Organization $unit) => $unit->type === OrganizationType::Department);
        $places = $units->reject(fn (Organization $unit) => $unit->type === OrganizationType::Department)->keyBy(fn (Organization $unit) => $unit->getKey());
        // Signed in at a department: its branch is shown too, as where it sits.
        foreach ($departments as $department) {
            if ($department->parent_id !== null && ! $places->has($department->parent_id) && ($parent = Organization::query()->find($department->parent_id)) !== null) {
                $places->put($parent->getKey(), $parent);
            }
        }

        $people = Employee::query()->whereIn('organization_id', $units->modelKeys())
            ->where('status', '!=', EmployeeStatus::Exited->value)
            ->pluck('organization_id')->countBy();
        $item = fn (Organization $unit) => [
            'id' => $unit->getKey(),
            'type' => $unit->type->value,
            'name' => $unit->displayName($locale),
            'names' => $unit->texts('name'),
            'status' => $unit->status->value,
            'version' => $unit->version,
            'employees' => (int) ($people[$unit->getKey()] ?? 0),
        ];

        return response()->json(['data' => $places->values()->map(fn (Organization $place) => [
            ...$item($place),
            'depth' => $place->depth,
            // Making, renaming and closing departments here: both HR and the organization's tree.
            'can_manage' => Gate::allows('hrm.manage', $place) && Gate::allows('organizations.manage', $place),
            'departments' => $departments->where('parent_id', $place->getKey())->map($item)->values(),
        ])->values()]);
    }
}
