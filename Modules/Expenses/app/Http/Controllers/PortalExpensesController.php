<?php

namespace Modules\Expenses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Portal\PortalAccess;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Http\JsonResponse;
use Modules\Expenses\Http\ExpensesPresenter;
use Modules\Expenses\Models\Claim;
use Modules\Expenses\Services\Place;
use Modules\Hrm\Directory\EmployeeDirectory;

/**
 * A portal member's own expense claims (B2B2C: an employee who uses the
 * client's portal): the sent claims of the employee records the client
 * linked to them (portal subject hrm.employee), where each stands. Read
 * only; drafts and receipts stay in the app.
 */
class PortalExpensesController extends Controller
{
    public function __construct(
        private CurrentContext $context,
        private PortalAccess $portal,
        private Place $place,
        private EmployeeDirectory $directory,
        private ExpensesPresenter $presenter,
    ) {}

    public function show(): JsonResponse
    {
        if (! $this->portal->isPortal()) {
            return response()->json(['data' => []]);
        }
        $company = $this->place->companyOf($this->context->organization());
        $data = [];
        foreach ($this->directory->many($company, $this->portal->subjectIds('hrm.employee')) as $employee) {
            $claims = $this->place->query(Claim::class, $company)->where('employee_id', $employee->id)->where('status', '!=', Claim::DRAFT)
                ->orderByDesc('submitted_at')->limit(50)->get();
            $data[] = [
                'employee' => ['id' => $employee->id, 'name' => $employee->name],
                'claims' => $this->presenter->claims($company, $claims),
            ];
        }

        return response()->json(['data' => $data]);
    }
}
