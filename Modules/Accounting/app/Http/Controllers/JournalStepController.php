<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Http\AccountingPresenter;
use Modules\Accounting\Http\Controllers\Concerns\FindsBooks;
use Modules\Accounting\Http\Requests\JournalStepRequest;
use Modules\Accounting\Services\Journals;

/**
 * Steps of a journal. Writers (accounting.post) submit, withdraw and
 * reverse; approvers (accounting.approve) approve and reject, never their
 * own journals. Reverse answers with the new, reversing journal.
 */
class JournalStepController extends Controller
{
    use FindsBooks;

    private const PERMISSIONS = [
        'submit' => 'accounting.post',
        'withdraw' => 'accounting.post',
        'reverse' => 'accounting.post',
        'approve' => 'accounting.approve',
        'reject' => 'accounting.approve',
    ];

    public function __construct(private Journals $journals, private AccountingPresenter $presenter) {}

    public function __invoke(JournalStepRequest $request, string $organization, string $journal, string $step): JsonResponse
    {
        $company = $this->company($organization);
        $found = $this->journalIn($company, $journal);
        Gate::authorize(self::PERMISSIONS[$step] ?? throw AccountingException::unknownStep(), $company);

        $version = (int) $request->validated('base_version');
        $actor = $request->user();
        if ($step === 'reverse' && $found->version !== $version) {
            throw AccountingException::versionConflict(['version' => $found->version, 'status' => $found->status->value]);
        }

        $result = match ($step) {
            'submit' => $this->journals->submit($company, $found, $version, $actor),
            'withdraw' => $this->journals->withdraw($company, $found, $version, $actor),
            'approve' => $this->journals->approve($company, $found, $version, $actor),
            'reject' => $this->journals->reject($company, $found, $version, (string) $request->validated('reason'), $actor),
            'reverse' => $this->journals->reverse($company, $found, $request->validated('entry_date'), (string) $request->validated('reason'), $actor),
        };

        return response()->json(['data' => $this->presenter->journal($result, $company)], $step === 'reverse' ? 201 : 200);
    }
}
