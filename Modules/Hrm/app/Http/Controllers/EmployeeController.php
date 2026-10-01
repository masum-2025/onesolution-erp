<?php

namespace Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Audit\AuditLogger;
use App\Platform\Support\Http\PerPage;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Hrm\Enums\EmployeeStatus;
use Modules\Hrm\Http\Controllers\Concerns\FindsHrmRecords;
use Modules\Hrm\Http\EmployeePresenter;
use Modules\Hrm\Http\Requests\HireEmployeeRequest;
use Modules\Hrm\Http\Requests\UpdateEmployeeRequest;
use Modules\Hrm\Models\Employee;
use Modules\Hrm\Services\EmployeeLifecycle;

/**
 * Employees of the organization in the address and the units below it.
 * Reading needs hrm.view, hiring and editing hrm.manage, full ids
 * hrm.view_sensitive (audited); always at the employee's own unit.
 */
class EmployeeController extends Controller
{
    use FindsHrmRecords;

    public function __construct(
        private EmployeeLifecycle $lifecycle,
        private EmployeePresenter $presenter,
        private AuditLogger $audit,
    ) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('hrm.view', $organization);

        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(EmployeeStatus::cases(), 'value'))],
            'position_id' => ['nullable', 'string', 'size:26'],
            'unit_id' => ['nullable', 'string', 'size:26'],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer'],
        ]);

        $units = $request->filled('unit_id') ? $this->subtreeIds($this->unitIn($organization, $request->string('unit_id')->toString())) : $this->subtreeIds($organization);
        $search = trim($request->string('q')->toString());

        $page = Employee::query()->with('position')
            ->whereIn('organization_id', $units)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('position_id'), fn ($query) => $query->where('position_id', $request->string('position_id')->toString()))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('full_name', 'like', '%'.$search.'%')
                ->orWhere('full_name_local', 'like', '%'.$search.'%')
                ->orWhere('employee_code', 'like', '%'.$search.'%')))
            ->orderBy('full_name')
            ->paginate(PerPage::from($request));

        return response()->json([
            'data' => collect($page->items())->map(fn (Employee $employee) => $this->presenter->listItem($employee))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(HireEmployeeRequest $request, string $organization): JsonResponse
    {
        $unit = $this->unitIn($this->findVisible($organization), $request->validated('organization_id'));
        Gate::authorize('hrm.manage', $unit);

        $employee = $this->lifecycle->hire($unit, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->detail($employee->load(['position', 'manager']))], 201);
    }

    public function show(string $organization, string $employee): JsonResponse
    {
        $employee = $this->employeeIn($this->findVisible($organization), $employee);
        Gate::authorize('hrm.view', $this->unitOf($employee));

        return response()->json(['data' => $this->presenter->detail($employee)]);
    }

    public function update(UpdateEmployeeRequest $request, string $organization, string $employee): JsonResponse
    {
        $employee = $this->employeeIn($this->findVisible($organization), $employee);
        Gate::authorize('hrm.manage', $this->unitOf($employee));

        $data = $request->validated();
        $updated = $this->lifecycle->update($employee, (int) $data['base_version'], $data, $request->user());

        return response()->json(['data' => $this->presenter->detail($updated->load(['position', 'manager']))]);
    }

    /**
     * Full national and tax ids: hrm.view_sensitive, and every look is audited.
     */
    public function sensitive(Request $request, string $organization, string $employee): JsonResponse
    {
        $employee = $this->employeeIn($this->findVisible($organization), $employee);
        Gate::authorize('hrm.view_sensitive', $this->unitOf($employee));

        $this->audit->record('hrm.sensitive_viewed', $employee, new: ['fields' => ['national_id', 'tax_id']], actor: $request->user(), organizationId: $employee->organization_id);

        return response()->json(['data' => ['national_id' => $employee->national_id, 'tax_id' => $employee->tax_id]])
            ->header('Cache-Control', 'no-store, private');
    }

    public function history(string $organization, string $employee): JsonResponse
    {
        $employee = $this->employeeIn($this->findVisible($organization), $employee);
        Gate::authorize('hrm.view', $this->unitOf($employee));

        return response()->json(['data' => $employee->events()->orderByDesc('effective_on')->orderByDesc('created_at')->get()
            ->map(fn ($event) => $this->presenter->event($event))->values()]);
    }

    private function unitOf(Employee $employee): Organization
    {
        return Organization::query()->findOrFail($employee->organization_id);
    }
}
