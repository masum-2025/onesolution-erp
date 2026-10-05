<?php

namespace Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Http\Controllers\Concerns\FindsPayroll;
use Modules\Payroll\Http\PayrollPresenter;
use Modules\Payroll\Http\Requests\BonusRequest;
use Modules\Payroll\Models\BonusLine;
use Modules\Payroll\Models\BonusRun;
use Modules\Payroll\Services\BankFile;
use Modules\Payroll\Services\Bonuses;
use Modules\Payroll\Services\Payrolls;

/**
 * A company's festival bonuses: read (payroll.view), opened, calculated,
 * changed line by line, sent and paid (payroll.run), approved or rejected
 * (payroll.approve; never one's own). All at the company.
 */
class BonusController extends Controller
{
    use FindsPayroll;

    private const PERMISSIONS = [
        'calculate' => 'payroll.run',
        'submit' => 'payroll.run',
        'pay' => 'payroll.run',
        'approve' => 'payroll.approve',
        'reject' => 'payroll.approve',
    ];

    public function __construct(private Payrolls $payrolls, private Bonuses $bonuses, private PayrollPresenter $presenter) {}

    public function index(string $organization): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        Gate::authorize('payroll.view', $company);

        return response()->json(['data' => $this->payrolls->query(BonusRun::class, $company)->orderByDesc('bonus_on')->limit(60)->get()
            ->map(fn (BonusRun $bonus) => $this->presenter->bonus($bonus))->values()]);
    }

    public function store(BonusRequest $request, string $organization): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        Gate::authorize('payroll.run', $company);

        return response()->json(['data' => $this->full($company, $this->bonuses->open($company, $request->validated(), $request->user()))], 201);
    }

    /** A bonus with every line and what the reader may do. */
    public function show(string $organization, string $bonus): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->bonusIn($company, $bonus);
        Gate::authorize('payroll.view', $company);

        return response()->json(['data' => [
            ...$this->full($company, $found),
            'lines' => $this->payrolls->query(BonusLine::class, $company)->where('bonus_run_id', $found->getKey())->orderBy('employee_name')->get()
                ->map(fn (BonusLine $line) => $this->presenter->bonusLine($line))->values(),
        ]]);
    }

    public function step(BonusRequest $request, string $organization, string $bonus, string $step): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->bonusIn($company, $bonus);
        Gate::authorize(self::PERMISSIONS[$step] ?? throw PayrollException::unknownStep(), $company);
        $version = (int) $request->validated('base_version');
        $actor = $request->user();

        $changed = match ($step) {
            'calculate' => $this->bonuses->calculate($company, $found, $version, $actor),
            'submit' => $this->bonuses->submit($company, $found, $version, $actor),
            'approve' => $this->bonuses->approve($company, $found, $version, $actor),
            'reject' => $this->bonuses->reject($company, $found, $version, (string) $request->validated('reason'), $actor),
            'pay' => $this->bonuses->pay($company, $found, $version, CarbonImmutable::parse($request->validated('paid_on'), 'UTC'), $actor),
        };

        return response()->json(['data' => $this->full($company, $changed)]);
    }

    public function updateLine(BonusRequest $request, string $organization, string $bonus, string $line): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->bonusIn($company, $bonus);
        Gate::authorize('payroll.run', $company);
        $item = $this->payrolls->query(BonusLine::class, $company)->where('bonus_run_id', $found->getKey())->whereKey($line)->first() ?? throw PayrollException::bonusNotFound();

        return response()->json(['data' => $this->presenter->bonusLine($this->bonuses->changeLine($company, $found, $item, $request->validated(), $request->user()))]);
    }

    public function destroy(Request $request, string $organization, string $bonus): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->bonusIn($company, $bonus);
        Gate::authorize('payroll.run', $company);
        $request->validate(['base_version' => ['required', 'integer', 'min:1']]);
        $this->bonuses->delete($company, $found, (int) $request->input('base_version'), $request->user());

        return response()->json(null, 204);
    }

    /** The bank file of an approved or paid bonus: like a month's (recent second step, audited, never cached). */
    public function bankFile(Request $request, string $organization, string $bonus, BankFile $bank, AuditLogger $audit): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->bonusIn($company, $bonus);
        Gate::authorize('payroll.run', $company);
        if (! in_array($found->status, [BonusRun::APPROVED, BonusRun::PAID], true)) {
            throw PayrollException::notApproved();
        }

        $rows = collect($bank->rows($company, $this->payrolls->query(BonusLine::class, $company)->where('bonus_run_id', $found->getKey())->orderBy('employee_name')->get()
            ->map(fn (BonusLine $line) => ['employee_id' => $line->employee_id, 'employee_code' => $line->employee_code, 'employee_name' => $line->employee_name, 'amount_minor' => $line->net_minor])));
        $audit->record('payroll.bonus_bank_file_taken', $found, new: ['bonus_on' => $found->bonus_on->toDateString(), 'lines' => $rows->count(), 'without_account' => $rows->whereNull('method')->count()],
            actor: $request->user(), organizationId: $company->getKey());

        return response()->json(['data' => ['period' => $found->bonus_on->toDateString(), 'currency' => $found->currency_code, 'rows' => $rows->values()]])
            ->header('Cache-Control', 'no-store, private');
    }

    private function bonusIn($company, string $id): BonusRun
    {
        return $this->payrolls->query(BonusRun::class, $company)->whereKey($id)->first() ?? throw PayrollException::bonusNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function full($company, BonusRun $bonus): array
    {
        $me = auth()->id();
        $runs = Gate::allows('payroll.run', $company);
        $approves = Gate::allows('payroll.approve', $company);
        $mine = in_array($me, [$bonus->created_by, $bonus->submitted_by], true);
        $draft = $bonus->status === BonusRun::DRAFT;

        return $this->presenter->bonus($bonus, [
            'calculate' => $draft && $runs,
            'change' => $draft && $runs,
            'submit' => $draft && $bonus->calculated_at !== null && $runs,
            'delete' => $draft && $runs,
            'approve' => $bonus->status === BonusRun::PENDING && ! $mine && $approves,
            'reject' => $bonus->status === BonusRun::PENDING && ! $mine && $approves,
            'pay' => $bonus->status === BonusRun::APPROVED && $runs,
            'bank_file' => in_array($bonus->status, [BonusRun::APPROVED, BonusRun::PAID], true) && $runs,
        ]);
    }
}
