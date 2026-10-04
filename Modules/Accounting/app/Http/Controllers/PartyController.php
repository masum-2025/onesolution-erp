<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Http\AccountingPresenter;
use Modules\Accounting\Http\Controllers\Concerns\FindsBooks;
use Modules\Accounting\Http\Requests\PartyRequest;
use Modules\Accounting\Models\Party;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\Parties;
use Modules\Accounting\Services\Receivables;

/**
 * Customers and vendors. People who sell (accounting.sell) look after
 * customers, people who buy (accounting.buy) vendors; a party that is both
 * needs both. Everyone who reads Accounting sees them.
 */
class PartyController extends Controller
{
    use FindsBooks;

    public function __construct(private Books $books, private Parties $parties, private AccountingPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.view', $company);
        $request->validate([
            'role' => ['nullable', 'string', 'in:customers,vendors'],
            'q' => ['nullable', 'string', 'max:100'],
            'inactive' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer'],
        ]);
        $search = trim($request->string('q')->toString());

        $page = $this->books->query(Party::class, $company)
            ->when($request->input('role') === 'customers', fn ($query) => $query->where('is_customer', true))
            ->when($request->input('role') === 'vendors', fn ($query) => $query->where('is_vendor', true))
            ->when(! $request->boolean('inactive'), fn ($query) => $query->where('is_active', true))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('code', 'like', '%'.$search.'%')
                ->orWhere('phone', 'like', '%'.$search.'%')))
            ->orderBy('name')
            ->paginate(PerPage::from($request));

        return response()->json([
            'data' => collect($page->items())->map(fn (Party $party) => $this->presenter->party($party))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(string $organization, string $party, Receivables $receivables): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.view', $company);
        $found = $this->partyIn($company, $party);

        return response()->json(['data' => $this->presenter->party($found, [
            'sales' => $found->is_customer ? $receivables->balanceOf($company, $found, 'sales') : 0,
            'purchases' => $found->is_vendor ? $receivables->balanceOf($company, $found, 'purchases') : 0,
        ])]);
    }

    public function store(PartyRequest $request, string $organization): JsonResponse
    {
        $company = $this->company($organization);
        $data = $request->validated();
        $this->authorizeRoles($company, (bool) ($data['is_customer'] ?? false), (bool) ($data['is_vendor'] ?? false));
        $this->books->assertSetUp($company);

        return response()->json(['data' => $this->presenter->party($this->parties->create($company, $data, $request->user()))], 201);
    }

    public function update(PartyRequest $request, string $organization, string $party): JsonResponse
    {
        $company = $this->company($organization);
        $found = $this->partyIn($company, $party);
        $data = $request->validated();
        $this->authorizeRoles($company, $found->is_customer || (bool) ($data['is_customer'] ?? false), $found->is_vendor || (bool) ($data['is_vendor'] ?? false));

        $updated = $this->parties->update($company, $found, (int) $data['base_version'], array_diff_key($data, ['base_version' => true]), $request->user());

        return response()->json(['data' => $this->presenter->party($updated)]);
    }

    private function authorizeRoles(Organization $company, bool $customer, bool $vendor): void
    {
        Gate::authorize('accounting.view', $company);
        if ($customer) {
            Gate::authorize('accounting.sell', $company);
        }
        if ($vendor) {
            Gate::authorize('accounting.buy', $company);
        }
    }
}
