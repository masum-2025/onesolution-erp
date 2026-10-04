<?php

namespace Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Http\Controllers\Concerns\FindsPayroll;
use Modules\Payroll\Http\PayrollPresenter;
use Modules\Payroll\Http\Requests\RunRequest;
use Modules\Payroll\Models\Adjustment;
use Modules\Payroll\Models\Run;
use Modules\Payroll\Models\RunApproval;
use Modules\Payroll\Models\Slip;
use Modules\Payroll\Models\SlipLine;
use Modules\Payroll\Services\Payrolls;
use Modules\Payroll\Services\Runs;

/**
 * A company's monthly payroll runs: read (payroll.view), opened,
 * calculated, adjusted, sent and paid (payroll.run), approved or rejected
 * (payroll.approve; never one's own). All at the company.
 */
class RunController extends Controller
{
    use FindsPayroll;

    private const PERMISSIONS = [
        'calculate' => 'payroll.run',
        'submit' => 'payroll.run',
        'pay' => 'payroll.run',
        'approve' => 'payroll.approve',
        'reject' => 'payroll.approve',
    ];

    public function __construct(
        private Payrolls $payrolls,
        private Runs $runs,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private PayrollPresenter $presenter,
    ) {}

    public function index(string $organization): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        Gate::authorize('payroll.view', $company);

        return response()->json(['data' => $this->payrolls->query(Run::class, $company)->orderByDesc('period')->limit(36)->get()
            ->map(fn (Run $run) => $this->presenter->run($run))->values()]);
    }

    public function store(RunRequest $request, string $organization): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        Gate::authorize('payroll.run', $company);

        return response()->json(['data' => $this->full($company, $this->runs->open($company, $request->validated('period'), $request->user()))], 201);
    }

    /** A run with its slips (no lines), adjustments and what the reader may do. */
    public function show(string $organization, string $run): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->runIn($company, $run);
        Gate::authorize('payroll.view', $company);

        return response()->json(['data' => [
            ...$this->full($company, $found),
            'slips' => $this->payrolls->query(Slip::class, $company)->where('run_id', $found->getKey())->orderBy('employee_name')->get()
                ->map(fn (Slip $slip) => $this->presenter->slip($slip))->values(),
            'adjustments' => $this->payrolls->query(Adjustment::class, $company)->where('run_id', $found->getKey())->orderBy('created_at')->get()
                ->map(fn (Adjustment $adjustment) => $this->presenter->adjustment($adjustment))->values(),
        ]]);
    }

    public function slip(string $organization, string $run, string $slip): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->runIn($company, $run);
        Gate::authorize('payroll.view', $company);
        $item = $this->payrolls->query(Slip::class, $company)->where('run_id', $found->getKey())->whereKey($slip)->first() ?? throw PayrollException::runNotFound();

        return response()->json(['data' => $this->presenter->slip($item, $this->payrolls->query(SlipLine::class, $company)->where('slip_id', $item->getKey())->orderBy('line_no')->get(), $found)]);
    }

    public function step(RunRequest $request, string $organization, string $run, string $step): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->runIn($company, $run);
        Gate::authorize(self::PERMISSIONS[$step] ?? throw PayrollException::unknownStep(), $company);
        $version = (int) $request->validated('base_version');
        $actor = $request->user();

        $changed = match ($step) {
            'calculate' => $this->runs->calculate($company, $found, $version, $actor),
            'submit' => $this->runs->submit($company, $found, $version, $actor),
            'approve' => $this->runs->approve($company, $found, $version, $actor),
            'reject' => $this->runs->reject($company, $found, $version, (string) $request->validated('reason'), $actor),
            'pay' => $this->runs->pay($company, $found, $version, CarbonImmutable::parse($request->validated('paid_on'), 'UTC'), $actor),
        };

        return response()->json(['data' => $this->full($company, $changed)]);
    }

    public function destroy(Request $request, string $organization, string $run): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->runIn($company, $run);
        Gate::authorize('payroll.run', $company);
        $request->validate(['base_version' => ['required', 'integer', 'min:1']]);
        $this->runs->delete($company, $found, $request->integer('base_version'), $request->user());

        return response()->json(null, 204);
    }

    public function adjust(RunRequest $request, string $organization, string $run): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->runIn($company, $run);
        Gate::authorize('payroll.run', $company);
        $employee = $this->employeeIn($company, $company, $request->validated('employee_id'), PayrollException::employeeNotFound());

        return response()->json(['data' => $this->presenter->adjustment($this->runs->adjust($company, $found, $employee, $request->safe()->except('employee_id'), $request->user()))], 201);
    }

    public function removeAdjustment(Request $request, string $organization, string $run, string $adjustment): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->runIn($company, $run);
        Gate::authorize('payroll.run', $company);
        $item = $this->payrolls->query(Adjustment::class, $company)->where('run_id', $found->getKey())->whereKey($adjustment)->first() ?? throw PayrollException::adjustmentNotFound();
        $this->runs->removeAdjustment($company, $item, $request->user());

        return response()->json(null, 204);
    }

    private function runIn(Organization $company, string $id): Run
    {
        return $this->payrolls->query(Run::class, $company)->whereKey($id)->first() ?? throw PayrollException::runNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function full(Organization $company, Run $run): array
    {
        $approvers = $this->payrolls->query(RunApproval::class, $company)->where('run_id', $run->getKey())->pluck('user_id')->all();
        $needed = (int) $this->rules->get('payroll.salary_approval_levels', $this->contexts->forOrganization($company));
        $me = auth()->id();
        $mine = in_array($me, [$run->created_by, $run->submitted_by], true);
        $runs = Gate::allows('payroll.run', $company);
        $approves = Gate::allows('payroll.approve', $company);

        return $this->presenter->run($run, [
            'calculate' => $run->status === Run::DRAFT && $runs,
            'adjust' => $run->status === Run::DRAFT && $runs,
            'submit' => $run->status === Run::DRAFT && $run->calculated_at !== null && $runs,
            'delete' => $run->status === Run::DRAFT && $runs,
            'approve' => $run->status === Run::PENDING && ! $mine && ! in_array($me, $approvers, true) && $approves,
            'reject' => $run->status === Run::PENDING && ! $mine && $approves,
            'pay' => $run->status === Run::APPROVED && $runs,
        ], count($approvers), $needed);
    }
}
