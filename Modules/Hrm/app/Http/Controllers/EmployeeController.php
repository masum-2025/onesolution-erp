<?php

namespace Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Support\Http\PerPage;
use App\Platform\Tenancy\Enums\MembershipStatus;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Hrm\Enums\EmployeeStatus;
use Modules\Hrm\Http\Controllers\Concerns\FindsHrmRecords;
use Modules\Hrm\Http\EmployeePresenter;
use Modules\Hrm\Http\Requests\HireEmployeeRequest;
use Modules\Hrm\Http\Requests\UpdateEmployeeRequest;
use Modules\Hrm\Models\CustomField;
use Modules\Hrm\Models\Employee;
use Modules\Hrm\Services\CustomFields;
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

    /**
     * What the hire and edit forms need at a unit: the choices and required
     * details from its rules, and where the probation and notice numbers
     * come from (shown next to them).
     */
    public function formOptions(Request $request, string $organization, RuleResolver $rules, RuleContextFactory $contexts, CustomFields $customFields): JsonResponse
    {
        $request->validate(['unit_id' => ['nullable', 'string', 'size:26']]);
        $unit = $this->unitIn($this->findVisible($organization), $request->input('unit_id'));
        Gate::authorize('hrm.view', $unit);

        $context = $contexts->forOrganization($unit);
        $choices = fn (string $rule) => array_map(
            fn (string $value) => ['value' => $value, 'label' => __("hrm::rules.{$rule}.options.{$value}")],
            array_values((array) $rules->get("hrm.{$rule}", $context)),
        );
        $number = function (string $key) use ($rules, $context, $unit) {
            $resolved = $rules->explain($key, $context);

            return [
                'value' => $resolved->value,
                'source' => [
                    'kind' => match (true) {
                        $resolved->sourceLevel === null => 'default',
                        $resolved->sourceScopeId === $unit->getKey() => 'self',
                        default => 'inherited',
                    },
                    'level' => $resolved->sourceLevel,
                    'name' => $resolved->sourceName,
                ],
            ];
        };
        $kind = (string) $rules->get('hrm.national_id_kind', $context);

        return response()->json(['data' => [
            'unit' => ['id' => $unit->getKey(), 'name' => $unit->displayName()],
            'employment_types' => $choices('employment_types'),
            'document_types' => $choices('document_types'),
            'required_fields' => array_values((array) $rules->get('hrm.required_fields', $context)),
            'national_id' => ['kind' => $kind, 'label' => __("hrm::rules.national_id_kind.options.{$kind}")],
            'genders' => array_map(fn (string $value) => ['value' => $value, 'label' => __("hrm::hrm.genders.{$value}")], ['female', 'male', 'other', 'undisclosed']),
            'document_max_kb' => (int) $rules->get('hrm.document_max_kb', $context),
            'probation_days' => $number('hrm.probation_days'),
            'notice_period_days' => $number('hrm.notice_period_days'),
            'custom_fields' => $customFields->applicableTo($unit)->map(fn (CustomField $field) => $this->presenter->customField($field, $unit))->values(),
            'can' => [
                'manage' => Gate::allows('hrm.manage', $unit),
                'exit' => Gate::allows('hrm.exit', $unit),
                'view_sensitive' => Gate::allows('hrm.view_sensitive', $unit),
                'configure' => Gate::allows('hrm.configure', $unit),
            ],
        ]]);
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

    /**
     * Logins that can be linked to the employee: active staff and portal
     * members of the company (name, email), not linked to another employee.
     */
    public function logins(Request $request, string $organization, string $employee): JsonResponse
    {
        $employee = $this->employeeIn($this->findVisible($organization), $employee);
        Gate::authorize('hrm.manage', $this->unitOf($employee));
        $search = trim((string) ($request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? ''));

        $company = Organization::query()->findOrFail($employee->company_id);
        $taken = Employee::query()->where('company_id', $company->getKey())->whereNotNull('user_id')->whereKeyNot($employee->getKey())
            ->where('status', '!=', EmployeeStatus::Exited->value)->pluck('user_id')->all();
        $users = OrganizationMembership::query()
            ->whereIn('organization_id', Organization::query()->subtreeOf($company)->pluck('id')->all())
            ->where('status', MembershipStatus::Active->value)
            ->whereIn('membership_type', [MembershipType::Staff->value, MembershipType::Portal->value])
            ->whereNotIn('user_id', $taken)
            ->distinct()->pluck('user_id')->all();

        return response()->json(['data' => User::query()->whereKey($users)
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')->limit(20)->get(['id', 'name', 'email'])
            ->map(fn (User $user) => $user->only(['id', 'name', 'email']))->values()]);
    }

    /** Link the login the employee uses (user_id), or unlink it (null). */
    public function login(Request $request, string $organization, string $employee): JsonResponse
    {
        $employee = $this->employeeIn($this->findVisible($organization), $employee);
        Gate::authorize('hrm.manage', $this->unitOf($employee));
        $data = $request->validate([
            'base_version' => ['required', 'integer', 'min:1'],
            'user_id' => ['present', 'nullable', 'string', 'max:26'],
        ]);

        $linked = $this->lifecycle->linkLogin($employee, (int) $data['base_version'], $data['user_id'], $request->user());

        return response()->json(['data' => $this->presenter->detail($linked->load(['position', 'manager']))]);
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
