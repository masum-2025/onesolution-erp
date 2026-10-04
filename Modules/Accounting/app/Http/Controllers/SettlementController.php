<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Enums\SettlementStatus;
use Modules\Accounting\Enums\SettlementType;
use Modules\Accounting\Http\AccountingPresenter;
use Modules\Accounting\Http\Controllers\Concerns\FindsBooks;
use Modules\Accounting\Http\Requests\SettlementRequest;
use Modules\Accounting\Models\Party;
use Modules\Accounting\Models\Settlement;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\Settlements;

/**
 * Money received (receipts, accounting.sell) and paid (payments,
 * accounting.buy): the list, one record, recording one. Approving,
 * rejecting, voiding and allocating later are steps.
 */
class SettlementController extends Controller
{
    use FindsBooks;

    public function __construct(private Books $books, private Settlements $settlements, private AccountingPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.view', $company);
        $request->validate([
            'type' => ['nullable', 'string', 'in:'.implode(',', array_column(SettlementType::cases(), 'value'))],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(SettlementStatus::cases(), 'value'))],
            'party_id' => ['nullable', 'string', 'size:26'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'unallocated' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer'],
        ]);

        $page = $this->books->query(Settlement::class, $company)
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')->toString()))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('party_id'), fn ($query) => $query->where('party_id', $request->string('party_id')->toString()))
            ->when($request->filled('from'), fn ($query) => $query->where('settled_on', '>=', $request->string('from')->toString()))
            ->when($request->filled('to'), fn ($query) => $query->where('settled_on', '<=', $request->string('to')->toString()))
            ->when($request->boolean('unallocated'), fn ($query) => $query->where('status', SettlementStatus::Posted->value)->whereColumn('allocated_minor', '<', 'amount_minor'))
            ->orderByDesc('settled_on')->orderByDesc('created_at')
            ->paginate(PerPage::from($request));

        $names = $this->books->query(Party::class, $company)->whereKey(collect($page->items())->pluck('party_id')->unique()->values()->all())->pluck('name', 'id')->all();

        return response()->json([
            'data' => collect($page->items())->map(fn (Settlement $settlement) => $this->presenter->settlementItem($settlement, $names))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(string $organization, string $settlement): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.view', $company);

        return response()->json(['data' => $this->presenter->settlement($this->settlementIn($company, $settlement), $company)]);
    }

    public function store(SettlementRequest $request, string $organization): JsonResponse
    {
        $company = $this->company($organization);
        $data = $request->validated();
        $type = SettlementType::from($data['type']);
        Gate::authorize($type === SettlementType::Receipt ? 'accounting.sell' : 'accounting.buy', $company);

        $settlement = $this->settlements->record($company, $type, $data, $request->user());

        return response()->json(['data' => $this->presenter->settlement($settlement, $company)], $settlement->wasRecentlyCreated ? 201 : 200);
    }
}
