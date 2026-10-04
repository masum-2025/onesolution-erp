<?php

namespace Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Portal\PortalAccess;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Http\PayrollPresenter;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Models\Slip;
use Modules\Payroll\Models\SlipLine;
use Modules\Payroll\Services\Payrolls;

/**
 * A portal member's own payslips (B2B2C: an employee who uses the client's
 * portal): the slips of the employee records the client linked to them
 * (portal subject hrm.employee), approved or paid runs only.
 */
class PortalSlipController extends Controller
{
    public function __construct(
        private CurrentContext $context,
        private PortalAccess $portal,
        private Payrolls $payrolls,
        private PayrollPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        [$company, $employees] = $this->scope();
        if ($company === null || $employees === []) {
            return response()->json(['data' => []]);
        }
        $runs = $this->runs($company);

        return response()->json(['data' => $this->payrolls->query(Slip::class, $company)->whereIn('employee_id', $employees)->whereIn('run_id', $runs->keys()->all())
            ->get()->sortByDesc(fn (Slip $slip) => $runs[$slip->run_id]->period)
            ->map(fn (Slip $slip) => $this->presenter->slip($slip, null, $runs[$slip->run_id]))->values()]);
    }

    public function show(string $slip): JsonResponse
    {
        [$company, $employees] = $this->scope();
        $found = $company === null ? null : $this->payrolls->query(Slip::class, $company)->whereKey($slip)->whereIn('employee_id', $employees)->first();
        $run = $found === null ? null : $this->runs($company)->get($found->run_id);
        if ($run === null) {
            throw PayrollException::runNotFound();
        }

        return response()->json(['data' => $this->presenter->slip($found, $this->payrolls->query(SlipLine::class, $company)->where('slip_id', $found->getKey())->orderBy('line_no')->get(), $run)]);
    }

    /**
     * @return array{0: Organization|null, 1: list<string>}
     */
    private function scope(): array
    {
        if (! $this->portal->isPortal()) {
            return [null, []];
        }

        return [$this->payrolls->companyOf($this->context->organization()), $this->portal->subjectIds('hrm.employee')];
    }

    private function runs($company)
    {
        return $this->payrolls->query(Run::class, $company)->whereIn('status', [Run::APPROVED, Run::PAID])->get()->keyBy('id');
    }
}
