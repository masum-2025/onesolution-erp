<?php

namespace Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Http\Controllers\Concerns\FindsPayroll;
use Modules\Payroll\Http\PayrollPresenter;
use Modules\Payroll\Http\Requests\IncrementRequest;
use Modules\Payroll\Models\IncrementApproval;
use Modules\Payroll\Models\IncrementArrear;
use Modules\Payroll\Models\IncrementLine;
use Modules\Payroll\Models\IncrementRound;
use Modules\Payroll\Services\Increments;
use Modules\Payroll\Services\Payrolls;

/**
 * A company's salary increment rounds: read (payroll.view), opened, filled,
 * changed line by line, sent, applied and cancelled (payroll.run), approved
 * or rejected (payroll.approve; never one's own, each level someone else).
 * Salaries are payroll's: appraisal managers without payroll.view see none.
 */
class IncrementController extends Controller
{
    use FindsPayroll;

    private const PERMISSIONS = [
        'fill' => 'payroll.run',
        'submit' => 'payroll.run',
        'apply' => 'payroll.run',
        'cancel' => 'payroll.run',
        'approve' => 'payroll.approve',
        'reject' => 'payroll.approve',
    ];

    public function __construct(private Payrolls $payrolls, private Increments $increments, private PayrollPresenter $presenter) {}

    public function index(string $organization): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        Gate::authorize('payroll.view', $company);

        return response()->json(['data' => $this->payrolls->query(IncrementRound::class, $company)->orderByDesc('effective_from')->orderByDesc('created_at')->limit(60)->get()
            ->map(fn (IncrementRound $round) => $this->presenter->increment($round))->values()]);
    }

    /** The finished appraisal cycles a round can follow, and the bands (empty without Appraisals). */
    public function sources(string $organization): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        Gate::authorize('payroll.run', $company);

        return response()->json(['data' => $this->increments->sources($company)]);
    }

    public function store(IncrementRequest $request, string $organization): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        Gate::authorize('payroll.run', $company);

        return response()->json(['data' => $this->full($company, $this->increments->open($company, $request->validated(), $request->user()))], 201);
    }

    /** A round with every line, the bands, and what the reader may do. */
    public function show(string $organization, string $round): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->roundIn($company, $round);
        Gate::authorize('payroll.view', $company);

        return response()->json(['data' => [
            ...$this->full($company, $found),
            'lines' => $this->payrolls->query(IncrementLine::class, $company)->where('round_id', $found->getKey())->orderBy('employee_name')->get()
                ->map(fn (IncrementLine $line) => $this->presenter->incrementLine($line))->values(),
            'bands' => $this->increments->sources($company)['bands'],
            // Arrears planned when applied: how much is paid and how much still waits for a run.
            'arrears' => [
                'paid_minor' => (int) $this->payrolls->query(IncrementArrear::class, $company)->where('round_id', $found->getKey())->where('status', IncrementArrear::PAID)->sum('amount_minor'),
                'waiting_minor' => (int) $this->payrolls->query(IncrementArrear::class, $company)->where('round_id', $found->getKey())->where('status', IncrementArrear::PLANNED)->sum('amount_minor'),
                'months' => $this->payrolls->query(IncrementArrear::class, $company)->where('round_id', $found->getKey())->distinct()->orderBy('period')->pluck('period')->values(),
            ],
        ]]);
    }

    public function step(IncrementRequest $request, string $organization, string $round, string $step): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->roundIn($company, $round);
        Gate::authorize(self::PERMISSIONS[$step] ?? throw PayrollException::unknownStep(), $company);
        $version = (int) $request->validated('base_version');
        $actor = $request->user();

        $changed = match ($step) {
            'fill' => $this->increments->refill($company, $found, $version, $actor),
            'submit' => $this->increments->submit($company, $found, $version, $actor),
            'approve' => $this->increments->approve($company, $found, $version, $actor),
            'reject' => $this->increments->reject($company, $found, $version, (string) $request->validated('reason'), $actor),
            'apply' => $this->increments->apply($company, $found, $version, $actor),
            'cancel' => $this->increments->cancel($company, $found, $version, $actor),
        };

        return response()->json(['data' => $this->full($company, $changed)]);
    }

    public function updateLine(IncrementRequest $request, string $organization, string $round, string $line): JsonResponse
    {
        [, $company] = $this->workplace($organization);
        $found = $this->roundIn($company, $round);
        Gate::authorize('payroll.run', $company);
        $item = $this->payrolls->query(IncrementLine::class, $company)->where('round_id', $found->getKey())->whereKey($line)->first() ?? throw PayrollException::incrementNotFound();

        return response()->json(['data' => $this->presenter->incrementLine($this->increments->changeLine($company, $found, $item, $request->validated(), $request->user()))]);
    }

    private function roundIn(Organization $company, string $id): IncrementRound
    {
        return $this->payrolls->query(IncrementRound::class, $company)->whereKey($id)->first() ?? throw PayrollException::incrementNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function full(Organization $company, IncrementRound $round): array
    {
        $me = auth()->id();
        $runs = Gate::allows('payroll.run', $company);
        $approves = Gate::allows('payroll.approve', $company);
        $mine = in_array($me, [$round->created_by, $round->submitted_by], true);
        $approvals = $this->payrolls->query(IncrementApproval::class, $company)->where('round_id', $round->getKey());
        $approvedByMe = (clone $approvals)->where('user_id', $me)->exists();
        $draft = $round->status === IncrementRound::DRAFT;
        $waiting = $round->status === IncrementRound::PENDING && ! $mine && ! $approvedByMe && $approves;

        return $this->presenter->increment($round, [
            'fill' => $draft && $runs,
            'change' => $draft && $runs,
            'submit' => $draft && $round->employees > 0 && $runs,
            'approve' => $waiting,
            'reject' => $waiting,
            'apply' => $round->status === IncrementRound::APPROVED && $runs,
            'cancel' => in_array($round->status, [IncrementRound::DRAFT, IncrementRound::APPROVED], true) && $runs,
        ], (clone $approvals)->count(), $this->increments->levelsNeeded($company));
    }
}
