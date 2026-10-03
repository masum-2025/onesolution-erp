<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Http\Controllers\Concerns\FindsBooks;
use Modules\Accounting\Http\Requests\PostingAccountRequest;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\ChartOfAccounts;

/**
 * Where other modules' postings go: every posting key modules declare, the
 * account the company chose for it, and choosing one.
 */
class PostingAccountController extends Controller
{
    use FindsBooks;

    public function __construct(private Books $books, private ChartOfAccounts $chart) {}

    public function index(string $organization): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.view', $company);

        return response()->json(['data' => $this->chart->postingAccounts($company)]);
    }

    public function update(PostingAccountRequest $request, string $organization, string $key): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.manage', $company);
        $this->books->assertSetUp($company);

        $mapping = $this->chart->setPostingAccount($company, $key, $request->validated('account_id'), $request->validated('base_version'), $request->user());

        return response()->json(['data' => ['key' => $mapping->posting_key, 'account_id' => $mapping->account_id, 'version' => $mapping->version]]);
    }
}
