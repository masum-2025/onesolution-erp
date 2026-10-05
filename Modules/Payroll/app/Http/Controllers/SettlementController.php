<?php

namespace Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Hrm\Directory\EmployeeRecord;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Http\Controllers\Concerns\FindsPayroll;
use Modules\Payroll\Http\PayrollPresenter;
use Modules\Payroll\Http\Requests\SettlementRequest;
use Modules\Payroll\Models\Settlement;
use Modules\Payroll\Models\SettlementLine;
use Modules\Payroll\Services\BankFile;
use Modules\Payroll\Services\Payrolls;
use Modules\Payroll\Services\Settlements;

/**
 * Final settlements of people who left: read (payroll.view), opened,
 * calculated, lines added by hand, sent and paid (payroll.run), approved or
 * rejected (payroll.approve; never one's own). All at the company.
 */
class SettlementController extends Controller
{
    use FindsPayroll;

    private const PERMISSIONS = [
        'calculate' => 'payroll.run',
        'submit' => 'payroll.run',
        'pay' => 'payroll.run',
        'approve' => 'payroll.approve',
        'reject' => 'payroll.approve',
    ];

    public function __construct(private Payrolls $payrolls, private Settlements $settlements, private PayrollPresenter $presenter) {}

    /** Settlements, newest first, and the people who left without one. */
    public function index(string $organization): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        Gate::authorize('payroll.view', $company);

        return response()->json([
            'data' => $this->payrolls->query(Settlement::class, $company)->orderByDesc('left_on')->limit(200)->get()
                ->map(fn (Settlement $settlement) => $this->presenter->settlement($settlement))->values(),
            'meta' => [
                'currency' => $this->payrolls->currency($company),
                'due' => array_map(fn (EmployeeRecord $employee) => [
                    'id' => $employee->id, 'code' => $employee->code, 'name' => $employee->name, 'left_on' => $employee->exitsOn?->toDateString(),
                ], $this->settlements->due($company)),
            ],
        ]);
    }

    public function store(SettlementRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('payroll.run', $company);
        $employee = $this->employeeIn($unit, $company, $request->validated('employee_id'), PayrollException::employeeNotFound());

        return response()->json(['data' => $this->full($company, $this->settlements->open($company, $employee, $request->user()))], 201);
    }

    public function show(string $organization, string $settlement): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->settlementIn($company, $settlement);
        Gate::authorize('payroll.view', $company);

        return response()->json(['data' => $this->full($company, $found)]);
    }

    public function step(SettlementRequest $request, string $organization, string $settlement, string $step): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->settlementIn($company, $settlement);
        Gate::authorize(self::PERMISSIONS[$step] ?? throw PayrollException::unknownStep(), $company);
        $version = (int) $request->validated('base_version');
        $actor = $request->user();

        $changed = match ($step) {
            'calculate' => $this->settlements->calculate($company, $found, $version, $actor),
            'submit' => $this->settlements->submit($company, $found, $version, $actor),
            'approve' => $this->settlements->approve($company, $found, $version, $actor),
            'reject' => $this->settlements->reject($company, $found, $version, (string) $request->validated('reason'), $actor),
            'pay' => $this->settlements->pay($company, $found, $version, CarbonImmutable::parse($request->validated('paid_on'), 'UTC'), $actor),
        };

        return response()->json(['data' => $this->full($company, $changed)]);
    }

    public function addLine(SettlementRequest $request, string $organization, string $settlement): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->settlementIn($company, $settlement);
        Gate::authorize('payroll.run', $company);
        $this->settlements->addLine($company, $found, $request->validated(), $request->user());

        return response()->json(['data' => $this->full($company, $this->settlementIn($company, $settlement))], 201);
    }

    public function removeLine(Request $request, string $organization, string $settlement, string $line): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->settlementIn($company, $settlement);
        Gate::authorize('payroll.run', $company);
        $item = $this->payrolls->query(SettlementLine::class, $company)->where('settlement_id', $found->getKey())->whereKey($line)->first() ?? throw PayrollException::settlementNotFound();
        $this->settlements->removeLine($company, $found, $item, $request->user());

        return response()->json(['data' => $this->full($company, $this->settlementIn($company, $settlement))]);
    }

    public function destroy(Request $request, string $organization, string $settlement): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->settlementIn($company, $settlement);
        Gate::authorize('payroll.run', $company);
        $request->validate(['base_version' => ['required', 'integer', 'min:1']]);
        $this->settlements->delete($company, $found, (int) $request->input('base_version'), $request->user());

        return response()->json(null, 204);
    }

    /** Where to pay an approved or paid settlement: like a month's bank file (recent second step, audited, never cached). */
    public function bankFile(Request $request, string $organization, string $settlement, BankFile $bank, AuditLogger $audit): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->settlementIn($company, $settlement);
        Gate::authorize('payroll.run', $company);
        if (! in_array($found->status, [Settlement::APPROVED, Settlement::PAID], true)) {
            throw PayrollException::notApproved();
        }
        $rows = collect($bank->rows($company, [['employee_id' => $found->employee_id, 'employee_code' => $found->employee_code, 'employee_name' => $found->employee_name, 'amount_minor' => $found->net_minor]]));
        $audit->record('payroll.settlement_bank_file_taken', $found, new: ['lines' => $rows->count(), 'without_account' => $rows->whereNull('method')->count()],
            actor: $request->user(), organizationId: $company->getKey());

        return response()->json(['data' => ['period' => $found->left_on->toDateString(), 'currency' => $found->currency_code, 'rows' => $rows->values()]])
            ->header('Cache-Control', 'no-store, private');
    }

    private function settlementIn($company, string $id): Settlement
    {
        return $this->payrolls->query(Settlement::class, $company)->whereKey($id)->first() ?? throw PayrollException::settlementNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function full($company, Settlement $settlement): array
    {
        $runs = Gate::allows('payroll.run', $company);
        $approves = Gate::allows('payroll.approve', $company);
        $mine = in_array(auth()->id(), [$settlement->created_by, $settlement->submitted_by], true);
        $draft = $settlement->status === Settlement::DRAFT;
        [$employeeFund, $employerFund] = $this->settlements->fund($company, $settlement->employee_id);

        return [
            ...$this->presenter->settlement($settlement, $this->settlements->linesOf($company, $settlement), [
                'calculate' => $draft && $runs,
                'change' => $draft && $runs,
                'submit' => $draft && $runs && $settlement->net_minor >= 0,
                'delete' => $draft && $runs,
                'approve' => $settlement->status === Settlement::PENDING && ! $mine && $approves,
                'reject' => $settlement->status === Settlement::PENDING && ! $mine && $approves,
                'pay' => $settlement->status === Settlement::APPROVED && $runs,
                'bank_file' => in_array($settlement->status, [Settlement::APPROVED, Settlement::PAID], true) && $runs,
            ]),
            'fund' => ['employee_minor' => $employeeFund, 'employer_minor' => $employerFund],
        ];
    }
}
