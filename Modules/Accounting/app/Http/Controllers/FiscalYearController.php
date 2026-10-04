<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Http\AccountingPresenter;
use Modules\Accounting\Http\Controllers\Concerns\FindsBooks;
use Modules\Accounting\Http\Requests\FiscalYearRequest;
use Modules\Accounting\Http\Requests\PeriodStepRequest;
use Modules\Accounting\Http\Requests\YearStepRequest;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Models\YearReopenRequest;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\FiscalCalendar;
use Modules\Accounting\Services\YearEnd;

/**
 * Fiscal years with their periods; adding the next year; closing and
 * reopening a period (accounting.close, audited, reopening needs a reason);
 * closing a year and asking to reopen it, which another person approves
 * (accounting.close).
 */
class FiscalYearController extends Controller
{
    use FindsBooks;

    public function __construct(private Books $books, private FiscalCalendar $calendar, private YearEnd $yearEnd, private AccountingPresenter $presenter) {}

    public function index(string $organization): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.view', $company);

        return response()->json(['data' => $this->books->query(FiscalYear::class, $company)->orderByDesc('starts_on')->get()
            ->map(fn (FiscalYear $year) => $this->presenter->year($year, $company))->values()]);
    }

    public function store(FiscalYearRequest $request, string $organization): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.manage', $company);
        $this->books->assertSetUp($company);

        $year = $this->calendar->addYear($company, $request->validated('starts_on'), $request->user());

        return response()->json(['data' => $this->presenter->year($year, $company)], 201);
    }

    public function period(PeriodStepRequest $request, string $organization, string $period, string $step): JsonResponse
    {
        $company = $this->company($organization);
        $found = $this->periodIn($company, $period);
        Gate::authorize('accounting.close', $company);

        $changed = match ($step) {
            'close' => $this->calendar->close($company, $found, $request->user()),
            'reopen' => $this->calendar->reopen($company, $found, (string) $request->validated('reason'), $request->user()),
            default => throw AccountingException::unknownStep(),
        };

        return response()->json(['data' => $this->presenter->period($changed)]);
    }

    /** Close a year, or ask to reopen a closed one. */
    public function year(YearStepRequest $request, string $organization, string $year, string $step): JsonResponse
    {
        $company = $this->company($organization);
        $found = $this->books->query(FiscalYear::class, $company)->whereKey($year)->first() ?? throw AccountingException::fiscalYearNotFound();
        Gate::authorize('accounting.close', $company);

        match ($step) {
            'close' => $this->yearEnd->close($company, $found, (int) $request->validated('base_version'), $request->user()),
            'reopen' => $this->yearEnd->requestReopen($company, $found, (string) $request->validated('reason'), $request->user()),
            default => throw AccountingException::unknownStep(),
        };

        return response()->json(['data' => $this->presenter->year($this->books->query(FiscalYear::class, $company)->findOrFail($found->getKey()), $company)]);
    }

    /** Approve or reject a request to reopen a year (not one's own approval). */
    public function reopenRequest(YearStepRequest $request, string $organization, string $reopenRequest, string $step): JsonResponse
    {
        $company = $this->company($organization);
        $found = $this->books->query(YearReopenRequest::class, $company)->whereKey($reopenRequest)->first() ?? throw AccountingException::reopenRequestNotFound();
        Gate::authorize('accounting.close', $company);

        match ($step) {
            'approve' => $this->yearEnd->approveReopen($company, $found, $request->user()),
            'reject' => $this->yearEnd->rejectReopen($company, $found, $request->validated('note'), $request->user()),
            default => throw AccountingException::unknownStep(),
        };

        return response()->json(['data' => $this->presenter->year($this->books->query(FiscalYear::class, $company)->findOrFail($found->fiscal_year_id), $company)]);
    }
}
