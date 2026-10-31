<?php

namespace Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Hrm\Http\Controllers\Concerns\FindsHrmRecords;
use Modules\Hrm\Http\EmployeePresenter;
use Modules\Hrm\Http\Requests\LoginSettingsRequest;
use Modules\Hrm\Models\Employee;
use Modules\Hrm\Services\EmployeeLogins;
use Modules\Hrm\Services\Units;

/**
 * Employees' logins with the company's first password: the settings (the
 * password and default roles, at the company), and giving or giving again a
 * login. Needs hrm.manage and the right to manage members, as inviting does.
 */
class EmployeeLoginController extends Controller
{
    use FindsHrmRecords;

    public function __construct(private EmployeeLogins $logins, private Units $units, private EmployeePresenter $presenter) {}

    public function settings(string $organization): JsonResponse
    {
        $company = $this->units->companyOf($this->findVisible($organization));
        Gate::authorize('hrm.manage', $company);

        return response()->json(['data' => $this->logins->settings($company)]);
    }

    public function saveSettings(LoginSettingsRequest $request, string $organization): JsonResponse
    {
        $company = $this->units->companyOf($this->findVisible($organization));
        Gate::authorize('hrm.manage', $company);
        Gate::authorize('manageMembers', $company);

        $this->logins->saveSettings($company, $request->validated('password'), $request->validated('role_ids'), (int) $request->validated('base_version'), $request->user());

        return response()->json(['data' => $this->logins->settings($company)]);
    }

    public function give(Request $request, string $organization, string $employee): JsonResponse
    {
        $employee = $this->employeeIn($this->findVisible($organization), $employee);
        $this->authorizeAt($employee);
        $data = $request->validate([
            'role_ids' => ['sometimes', 'array', 'max:20'],
            'role_ids.*' => ['string', 'size:26', 'distinct'],
        ]);

        $given = $this->logins->give($employee, $request->user(), $data['role_ids'] ?? null);

        return response()->json(['data' => $this->presenter->detail($given['employee']->load(['position', 'manager'])), 'meta' => ['login' => $given['login']]]);
    }

    public function giveMany(Request $request, string $organization): JsonResponse
    {
        $scope = $this->findVisible($organization);
        Gate::authorize('hrm.manage', $scope);
        $data = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1', 'max:200'],
            'employee_ids.*' => ['string', 'size:26', 'distinct'],
        ]);

        $results = $this->logins->giveMany($scope, $data['employee_ids'], $request->user());

        return response()->json(['data' => $results, 'meta' => [
            'created' => count(array_filter($results, fn (array $row) => $row['login'] === 'created')),
            'linked' => count(array_filter($results, fn (array $row) => $row['login'] === 'linked')),
            'failed' => count(array_filter($results, fn (array $row) => $row['error'] !== null)),
        ]]);
    }

    public function reset(Request $request, string $organization, string $employee): JsonResponse
    {
        $employee = $this->employeeIn($this->findVisible($organization), $employee);
        $this->authorizeAt($employee);

        $this->logins->reset($employee, $request->user());

        return response()->json(['data' => $this->presenter->detail($employee->fresh()->load(['position', 'manager']))]);
    }

    private function authorizeAt(Employee $employee): void
    {
        $unit = Organization::query()->findOrFail($employee->organization_id);
        Gate::authorize('hrm.manage', $unit);
        Gate::authorize('manageMembers', $unit);
    }
}
