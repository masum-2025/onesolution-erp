<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Http\AccountingPresenter;
use Modules\Accounting\Http\Controllers\Concerns\FindsBooks;
use Modules\Accounting\Http\Requests\DocumentStepRequest;
use Modules\Accounting\Services\Documents;

/**
 * Steps of a document. Writers (accounting.sell / accounting.buy) submit and
 * apply credits; approvers (accounting.approve) approve, reject and void,
 * never their own documents.
 */
class DocumentStepController extends Controller
{
    use FindsBooks;

    private const APPROVER_STEPS = ['approve', 'reject', 'void'];

    private const WRITER_STEPS = ['submit', 'apply'];

    public function __construct(private Documents $documents, private AccountingPresenter $presenter) {}

    public function __invoke(DocumentStepRequest $request, string $organization, string $document, string $step): JsonResponse
    {
        $company = $this->company($organization);
        $found = $this->documentIn($company, $document);
        $permission = match (true) {
            in_array($step, self::APPROVER_STEPS, true) => 'accounting.approve',
            in_array($step, self::WRITER_STEPS, true) => $found->type->isSales() ? 'accounting.sell' : 'accounting.buy',
            default => throw AccountingException::unknownStep(),
        };
        Gate::authorize($permission, $company);

        $version = (int) $request->validated('base_version');
        $actor = $request->user();
        $result = match ($step) {
            'submit' => $this->documents->submit($company, $found, $version, $actor),
            'approve' => $this->documents->approve($company, $found, $version, $actor),
            'reject' => $this->documents->reject($company, $found, $version, (string) $request->validated('reason'), $actor),
            'void' => $this->documents->void($company, $found, $version, (string) $request->validated('reason'), $actor),
            'apply' => $this->documents->applyCredit($company, $found, $version, $request->validated('allocations'), $actor),
        };

        return response()->json(['data' => $this->presenter->document($result, $company)]);
    }
}
