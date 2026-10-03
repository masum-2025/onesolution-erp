<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Http\Controllers\Concerns\FindsBooks;
use Modules\Accounting\Http\Requests\ReportRequest;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\Reports;

/**
 * Trial balance, account ledger, profit and loss, balance sheet. Dates
 * default to today in the company's timezone.
 */
class ReportController extends Controller
{
    use FindsBooks;

    public function __construct(private Books $books, private Reports $reports) {}

    public function __invoke(ReportRequest $request, string $organization, string $report): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.view', $company);
        $this->books->assertSetUp($company);

        $data = $request->validated();
        $costCentres = isset($data['cost_centre_id']) ? $this->books->costCentreTree($company, $data['cost_centre_id']) : null;
        $asOf = $data['as_of'] ?? $this->books->today($company)->toDateString();

        return response()->json(['data' => match ($report) {
            'trial-balance' => $this->reports->trialBalance($company, $asOf, $costCentres),
            'ledger' => $this->reports->ledger($company, $this->accountIn($company, $data['account_id']), $data['from'], $data['to'], $costCentres),
            'profit-loss' => $this->reports->profitAndLoss($company, $data['from'], $data['to'], $costCentres),
            'balance-sheet' => $this->reports->balanceSheet($company, $asOf, $costCentres),
            default => throw AccountingException::unknownStep(),
        }]);
    }
}
