<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Exceptions\AccountingException;
use Modules\Accounting\Http\AccountingPresenter;
use Modules\Accounting\Http\Controllers\Concerns\FindsBooks;
use Modules\Accounting\Http\Requests\OpeningRequest;
use Modules\Accounting\Http\Requests\OpeningStepRequest;
use Modules\Accounting\Models\FiscalYear;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\Openings;

/**
 * The company's opening balances: read (accounting.view), write, send and
 * throw away the draft (accounting.manage), approve or reject it
 * (accounting.approve, never one's own).
 */
class OpeningController extends Controller
{
    use FindsBooks;

    private const PERMISSIONS = [
        'submit' => 'accounting.manage',
        'withdraw' => 'accounting.manage',
        'approve' => 'accounting.approve',
        'reject' => 'accounting.approve',
    ];

    public function __construct(private Books $books, private Openings $openings, private AccountingPresenter $presenter) {}

    public function show(string $organization): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.view', $company);
        $this->books->assertSetUp($company);
        $opening = $this->openings->current($company);

        return response()->json([
            'data' => $opening === null ? null : $this->presenter->opening($opening, $company),
            'meta' => [
                'currency' => $this->books->currency($company),
                // The first day of the books, where most companies start.
                'suggested_date' => $this->books->query(FiscalYear::class, $company)->orderBy('starts_on')->value('starts_on')?->toDateString(),
                'can_write' => Gate::allows('accounting.manage', $company),
            ],
        ]);
    }

    public function update(OpeningRequest $request, string $organization): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.manage', $company);

        $opening = $this->openings->save($company, $request->safe()->only(['opening_date', 'lines']), $request->validated('base_version'), $request->user());

        return response()->json(['data' => $this->presenter->opening($opening, $company)]);
    }

    public function destroy(Request $request, string $organization): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.manage', $company);
        $request->validate(['base_version' => ['required', 'integer', 'min:1']]);

        $this->openings->delete($company, $request->integer('base_version'), $request->user());

        return response()->json(null, 204);
    }

    public function step(OpeningStepRequest $request, string $organization, string $step): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize(self::PERMISSIONS[$step] ?? throw AccountingException::unknownStep(), $company);

        $version = (int) $request->validated('base_version');
        $actor = $request->user();
        $opening = match ($step) {
            'submit' => $this->openings->submit($company, $version, $actor),
            'withdraw' => $this->openings->withdraw($company, $version, $actor),
            'approve' => $this->openings->approve($company, $version, $actor),
            'reject' => $this->openings->reject($company, $version, (string) $request->validated('reason'), $actor),
        };

        return response()->json(['data' => $this->presenter->opening($opening, $company)]);
    }
}
