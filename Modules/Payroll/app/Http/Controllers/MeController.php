<?php

namespace Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Http\Controllers\Concerns\FindsPayroll;
use Modules\Payroll\Http\PayrollPresenter;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Models\Slip;
use Modules\Payroll\Models\SlipLine;
use Modules\Payroll\Services\OwnPay;
use Modules\Payroll\Services\Payrolls;

/**
 * An employee's own payslips, loans and bonuses (through the login HR
 * linked to them): approved ones only, never a draft, never anyone else's.
 */
class MeController extends Controller
{
    use FindsPayroll;

    public function __construct(private Payrolls $payrolls, private EmployeeDirectory $directory, private PayrollPresenter $presenter) {}

    public function slips(Request $request, string $organization): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $employee = $this->directory->forUser($company, $request->user()) ?? throw PayrollException::employeeNotFound();
        $runs = $this->payrolls->query(Run::class, $company)->whereIn('status', [Run::APPROVED, Run::PAID])->get()->keyBy('id');

        return response()->json(['data' => $this->payrolls->query(Slip::class, $company)->where('employee_id', $employee->id)->whereIn('run_id', $runs->keys()->all())
            ->get()->sortByDesc(fn (Slip $slip) => $runs[$slip->run_id]->period)
            ->map(fn (Slip $slip) => $this->presenter->slip($slip, null, $runs[$slip->run_id]))->values()]);
    }

    public function slip(Request $request, string $organization, string $slip): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $employee = $this->directory->forUser($company, $request->user()) ?? throw PayrollException::employeeNotFound();
        $found = $this->payrolls->query(Slip::class, $company)->whereKey($slip)->where('employee_id', $employee->id)->first();
        $run = $found === null ? null : $this->payrolls->query(Run::class, $company)->whereKey($found->run_id)->whereIn('status', [Run::APPROVED, Run::PAID])->first();
        if ($run === null) {
            throw PayrollException::runNotFound();
        }

        return response()->json(['data' => $this->presenter->slip($found, $this->payrolls->query(SlipLine::class, $company)->where('slip_id', $found->getKey())->orderBy('line_no')->get(), $run)]);
    }

    public function loans(Request $request, string $organization, OwnPay $own): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $employee = $this->directory->forUser($company, $request->user()) ?? throw PayrollException::employeeNotFound();

        return response()->json(['data' => $own->loans($company, [$employee->id])]);
    }

    public function bonuses(Request $request, string $organization, OwnPay $own): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $employee = $this->directory->forUser($company, $request->user()) ?? throw PayrollException::employeeNotFound();

        return response()->json(['data' => $own->bonuses($company, [$employee->id])]);
    }

    public function fund(Request $request, string $organization, OwnPay $own): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $employee = $this->directory->forUser($company, $request->user()) ?? throw PayrollException::employeeNotFound();

        return response()->json(['data' => $own->fund($company, [$employee->id])]);
    }

    public function settlements(Request $request, string $organization, OwnPay $own): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $employee = $this->directory->forUser($company, $request->user()) ?? throw PayrollException::employeeNotFound();

        return response()->json(['data' => $own->settlements($company, [$employee->id])]);
    }

    public function settlement(Request $request, string $organization, string $settlement, OwnPay $own): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $employee = $this->directory->forUser($company, $request->user()) ?? throw PayrollException::employeeNotFound();

        return response()->json(['data' => $own->settlement($company, [$employee->id], $settlement) ?? throw PayrollException::settlementNotFound()]);
    }

    public function bonus(Request $request, string $organization, string $line, OwnPay $own): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $employee = $this->directory->forUser($company, $request->user()) ?? throw PayrollException::employeeNotFound();

        return response()->json(['data' => $own->bonusLine($company, [$employee->id], $line) ?? throw PayrollException::bonusNotFound()]);
    }
}
