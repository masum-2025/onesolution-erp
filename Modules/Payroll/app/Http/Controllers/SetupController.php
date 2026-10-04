<?php

namespace Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Http\Controllers\Concerns\FindsPayroll;
use Modules\Payroll\Http\PayrollPresenter;
use Modules\Payroll\Http\Requests\EmployeePayRequest;
use Modules\Payroll\Http\Requests\SetupRequest;
use Modules\Payroll\Models\Component;
use Modules\Payroll\Models\PaymentDetail;
use Modules\Payroll\Models\Salary;
use Modules\Payroll\Models\Structure;
use Modules\Payroll\Services\Payrolls;
use Modules\Payroll\Services\PaySetup;
use Modules\Payroll\Services\Salaries;

/**
 * The company's pay make-up (components, structures; read with
 * payroll.view, changed with payroll.run at the company) and each
 * employee's salary and payment details (at the employee's unit).
 */
class SetupController extends Controller
{
    use FindsPayroll;

    public function __construct(
        private Payrolls $payrolls,
        private PaySetup $setup,
        private Salaries $salaries,
        private PayrollPresenter $presenter,
    ) {}

    public function components(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('payroll.view', $unit);

        return response()->json(['data' => $this->payrolls->query(Component::class, $company)->orderBy('sort')->orderBy('code')->get()
            ->map(fn (Component $component) => $this->presenter->component($component))->values()]);
    }

    public function storeComponent(SetupRequest $request, string $organization): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        Gate::authorize('payroll.run', $company);

        return response()->json(['data' => $this->presenter->component($this->setup->createComponent($company, $request->validated(), $request->user()))], 201);
    }

    public function updateComponent(SetupRequest $request, string $organization, string $component): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->payrolls->query(Component::class, $company)->whereKey($component)->first() ?? throw PayrollException::componentNotFound();
        Gate::authorize('payroll.run', $company);
        $data = $request->validated();

        return response()->json(['data' => $this->presenter->component($this->setup->updateComponent($company, $found, (int) $data['base_version'], $data, $request->user()))]);
    }

    public function structures(string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('payroll.view', $unit);

        return response()->json(['data' => $this->payrolls->query(Structure::class, $company)->orderBy('code')->get()
            ->map(fn (Structure $structure) => $this->presenter->structure($structure, $this->setup->items($company, $structure)))->values()]);
    }

    public function storeStructure(SetupRequest $request, string $organization): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        Gate::authorize('payroll.run', $company);
        $structure = $this->setup->createStructure($company, $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->structure($structure, $this->setup->items($company, $structure))], 201);
    }

    public function updateStructure(SetupRequest $request, string $organization, string $structure): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->payrolls->query(Structure::class, $company)->whereKey($structure)->first() ?? throw PayrollException::structureNotFound();
        Gate::authorize('payroll.run', $company);
        $data = $request->validated();
        $changed = $this->setup->updateStructure($company, $found, (int) $data['base_version'], $data, $request->user());

        return response()->json(['data' => $this->presenter->structure($changed, $this->setup->items($company, $changed))]);
    }

    /** An employee's salary history (newest first) and payment details (masked). */
    public function employee(string $organization, string $employee): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->employeeIn($unit, $company, $employee, PayrollException::employeeNotFound());
        Gate::authorize('payroll.view', $this->unitOf($found->unitId));

        return response()->json(['data' => [
            'employee' => ['id' => $found->id, 'name' => $found->name, 'code' => $found->code],
            'salaries' => $this->payrolls->query(Salary::class, $company)->where('employee_id', $found->id)->orderByDesc('effective_from')->limit(50)->get()
                ->map(fn (Salary $salary) => $this->presenter->salary($salary))->values(),
            'payment' => $this->presenter->paymentDetail($this->payrolls->query(PaymentDetail::class, $company)->where('employee_id', $found->id)->first()),
        ]]);
    }

    public function setSalary(EmployeePayRequest $request, string $organization, string $employee): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->employeeIn($unit, $company, $employee, PayrollException::employeeNotFound());
        Gate::authorize('payroll.run', $this->unitOf($found->unitId));

        return response()->json(['data' => $this->presenter->salary($this->salaries->assign($company, $found, $request->validated(), $request->user()))], 201);
    }

    public function setPayment(EmployeePayRequest $request, string $organization, string $employee): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->employeeIn($unit, $company, $employee, PayrollException::employeeNotFound());
        Gate::authorize('payroll.run', $this->unitOf($found->unitId));

        return response()->json(['data' => $this->presenter->paymentDetail($this->salaries->setPaymentDetails($company, $found, $request->validated(), $request->user()))]);
    }
}
