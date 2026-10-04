<?php

namespace Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Hrm\Directory\EmployeeDirectory;
use Modules\Hrm\Directory\EmployeeRecord;
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
            ->map(fn (Structure $structure) => $this->presenter->structure($structure, $this->setup->items($company, $structure)))->values(), 'meta' => ['currency' => $this->payrolls->currency($company)]]);
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

    /**
     * Employees of the unit (and below it) with the salary in force today
     * and how they are paid (masked), by name; ?search= narrows by name or code.
     */
    public function employees(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('payroll.view', $unit);
        $search = mb_strtolower(trim((string) ($request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? '')));
        $today = CarbonImmutable::now();

        $employees = collect(app(EmployeeDirectory::class)->inUnits($company, $this->payrolls->subtreeIds($unit)))
            ->filter(fn (EmployeeRecord $employee) => $employee->exitsOn === null || $employee->exitsOn->greaterThanOrEqualTo($today->subMonths(2)))
            ->filter(fn (EmployeeRecord $employee) => $search === '' || str_contains(mb_strtolower($employee->name), $search) || str_contains(mb_strtolower($employee->code), $search))
            ->take(300)->values();
        $ids = $employees->pluck('id')->all();
        $salaries = $this->payrolls->query(Salary::class, $company)->whereIn('employee_id', $ids)
            ->where('effective_from', '<=', $today->toDateString())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $today->toDateString()))
            ->get()->keyBy('employee_id');
        $payments = $this->payrolls->query(PaymentDetail::class, $company)->whereIn('employee_id', $ids)->get()->keyBy('employee_id');
        $structures = $this->payrolls->query(Structure::class, $company)->get()->keyBy('id');

        return response()->json(['data' => $employees->map(fn (EmployeeRecord $employee) => [
            'id' => $employee->id, 'name' => $employee->name, 'code' => $employee->code, 'unit_id' => $employee->unitId, 'status' => $employee->status,
            'basic_minor' => $salaries[$employee->id]->basic_minor ?? null,
            'structure' => isset($salaries[$employee->id]) ? ($structures[$salaries[$employee->id]->structure_id]->name ?? null) : null,
            'payment_method' => $payments[$employee->id]->method ?? null,
        ])->values(), 'meta' => ['currency' => $this->payrolls->currency($company)]]);
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
        ], 'meta' => ['currency' => $this->payrolls->currency($company)]]);
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
