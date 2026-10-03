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
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\FiscalCalendar;

/**
 * Fiscal years with their periods; adding the next year; closing and
 * reopening a period (accounting.close, audited, reopening needs a reason).
 */
class FiscalYearController extends Controller
{
    use FindsBooks;

    public function __construct(private Books $books, private FiscalCalendar $calendar, private AccountingPresenter $presenter) {}

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
}
