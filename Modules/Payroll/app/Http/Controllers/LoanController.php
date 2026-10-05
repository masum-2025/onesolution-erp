<?php

namespace Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Http\Controllers\Concerns\FindsPayroll;
use Modules\Payroll\Http\PayrollPresenter;
use Modules\Payroll\Http\Requests\LoanRequest;
use Modules\Payroll\Models\Loan;
use Modules\Payroll\Services\Loans;
use Modules\Payroll\Services\Payrolls;

/**
 * Loans and advances of a company's employees: read (payroll.view), asked
 * for, cancelled and held back a month (payroll.run), approved or rejected
 * (payroll.approve; never one's own). All at the company.
 */
class LoanController extends Controller
{
    use FindsPayroll;

    private const STATUSES = [Loan::PENDING, Loan::ACTIVE, Loan::CLOSED, Loan::REJECTED, Loan::CANCELLED];

    public function __construct(private Payrolls $payrolls, private Loans $loans, private PayrollPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        Gate::authorize('payroll.view', $company);
        $filters = $request->validate(['status' => ['nullable', 'in:'.implode(',', self::STATUSES)], 'employee_id' => ['nullable', 'string', 'max:26']]);

        $query = $this->payrolls->query(Loan::class, $company)->orderByDesc('created_at')->limit(300);
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }

        return response()->json([
            'data' => $query->get()->map(fn (Loan $loan) => $this->presenter->loan($loan))->values(),
            'meta' => ['currency' => $this->payrolls->currency($company)],
        ]);
    }

    public function store(LoanRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('payroll.run', $company);
        $employee = $this->employeeIn($unit, $company, $request->validated('employee_id'), PayrollException::employeeNotFound());
        $loan = $this->loans->request($company, $employee, $request->safe()->except('employee_id'), $request->user());

        return response()->json(['data' => $this->full($company, $loan)], 201);
    }

    public function show(string $organization, string $loan): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        Gate::authorize('payroll.view', $company);

        return response()->json(['data' => $this->full($company, $this->loanIn($company, $loan))]);
    }

    public function step(LoanRequest $request, string $organization, string $loan, string $step): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->loanIn($company, $loan);
        $permission = ['approve' => 'payroll.approve', 'reject' => 'payroll.approve', 'cancel' => 'payroll.run'][$step] ?? throw PayrollException::unknownStep();
        Gate::authorize($permission, $company);
        $version = (int) $request->validated('base_version');
        $note = $request->validated('note');

        $changed = match ($step) {
            'approve' => $this->loans->approve($company, $found, $version, $note, $request->user()),
            'reject' => $this->loans->reject($company, $found, $version, (string) $note, $request->user()),
            'cancel' => $this->loans->cancel($company, $found, $version, $request->user()),
        };

        return response()->json(['data' => $this->full($company, $changed)]);
    }

    public function skip(LoanRequest $request, string $organization, string $loan): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->loanIn($company, $loan);
        Gate::authorize('payroll.run', $company);
        $this->loans->skip($company, $found, $request->validated('period'), $request->validated('reason'), $request->user());

        return response()->json(['data' => $this->full($company, $this->loanIn($company, $loan))], 201);
    }

    public function unskip(string $organization, string $loan, string $period): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->loanIn($company, $loan);
        Gate::authorize('payroll.run', $company);
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw PayrollException::loanNotFound();
        }
        $this->loans->unskip($company, $found, $period, request()->user());

        return response()->json(['data' => $this->full($company, $this->loanIn($company, $loan))]);
    }

    private function loanIn($company, string $id): Loan
    {
        return $this->payrolls->query(Loan::class, $company)->whereKey($id)->first() ?? throw PayrollException::loanNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function full($company, Loan $loan): array
    {
        $runs = Gate::allows('payroll.run', $company);
        $approves = Gate::allows('payroll.approve', $company);
        $pending = $loan->status === Loan::PENDING;

        return $this->presenter->loan($loan, $this->loans->schedule($company, $loan), [
            'approve' => $pending && $approves && $loan->created_by !== auth()->id(),
            'reject' => $pending && $approves && $loan->created_by !== auth()->id(),
            'cancel' => $pending && $runs,
            'skip' => $loan->status === Loan::ACTIVE && $runs,
        ]);
    }
}
