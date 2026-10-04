<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Enums\SettlementType;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Http\AccountingPresenter;
use Modules\Accounting\Http\Controllers\Concerns\FindsBooks;
use Modules\Accounting\Http\Requests\SettlementStepRequest;
use Modules\Accounting\Services\Settlements;

/**
 * Steps of money received or paid: approvers approve, reject and void
 * (never their own); writers allocate money not yet set against documents.
 */
class SettlementStepController extends Controller
{
    use FindsBooks;

    public function __construct(private Settlements $settlements, private AccountingPresenter $presenter) {}

    public function __invoke(SettlementStepRequest $request, string $organization, string $settlement, string $step): JsonResponse
    {
        $company = $this->company($organization);
        $found = $this->settlementIn($company, $settlement);
        $permission = match ($step) {
            'approve', 'reject', 'void' => 'accounting.approve',
            'allocate' => $found->type === SettlementType::Receipt ? 'accounting.sell' : 'accounting.buy',
            default => throw AccountingException::unknownStep(),
        };
        Gate::authorize($permission, $company);

        $version = (int) $request->validated('base_version');
        $actor = $request->user();
        $result = match ($step) {
            'approve' => $this->settlements->approve($company, $found, $version, $actor),
            'reject' => $this->settlements->reject($company, $found, $version, (string) $request->validated('reason'), $actor),
            'void' => $this->settlements->void($company, $found, $version, (string) $request->validated('reason'), $actor),
            'allocate' => $this->settlements->allocate($company, $found, $version, $request->validated('allocations'), $actor),
        };

        return response()->json(['data' => $this->presenter->settlement($result, $company)]);
    }
}
