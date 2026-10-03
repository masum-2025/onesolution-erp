<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Http\AccountingPresenter;
use Modules\Accounting\Http\Controllers\Concerns\FindsBooks;
use Modules\Accounting\Http\Requests\AccountRequest;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\ChartOfAccounts;

/**
 * The company's chart of accounts: the whole list in code order (the app
 * draws the tree from parent_id), adding and changing accounts.
 */
class AccountController extends Controller
{
    use FindsBooks;

    public function __construct(private Books $books, private ChartOfAccounts $chart, private AccountingPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.view', $company);
        $request->validate(['archived' => ['nullable', 'boolean']]);

        return response()->json(['data' => $this->books->query(Account::class, $company)
            ->when(! $request->boolean('archived'), fn ($query) => $query->where('status', 'active'))
            ->orderBy('code')->get()
            ->map(fn (Account $account) => $this->presenter->account($account))->values()]);
    }

    public function store(AccountRequest $request, string $organization): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.manage', $company);
        $this->books->assertSetUp($company);

        return response()->json(['data' => $this->presenter->account($this->chart->create($company, $request->validated(), $request->user()))], 201);
    }

    public function update(AccountRequest $request, string $organization, string $account): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.manage', $company);
        $found = $this->accountIn($company, $account);

        $data = $request->validated();
        $updated = $this->chart->update($company, $found, (int) $data['base_version'], array_diff_key($data, ['base_version' => true]), $request->user());

        return response()->json(['data' => $this->presenter->account($updated)]);
    }
}
