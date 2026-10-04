<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Enums\DocumentStatus;
use Modules\Accounting\Enums\DocumentType;
use Modules\Accounting\Http\AccountingPresenter;
use Modules\Accounting\Http\Controllers\Concerns\FindsBooks;
use Modules\Accounting\Http\Requests\DocumentRequest;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\Party;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\Documents;

/**
 * Invoices, credit notes, bills and vendor credits: the list (filters,
 * overdue only), one document, writing and changing drafts, removing one.
 * Sales documents need accounting.sell, purchase documents accounting.buy.
 */
class DocumentController extends Controller
{
    use FindsBooks;

    public function __construct(private Books $books, private Documents $documents, private AccountingPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.view', $company);
        $request->validate([
            'type' => ['nullable', 'string', 'in:'.implode(',', array_column(DocumentType::cases(), 'value'))],
            'side' => ['nullable', 'string', 'in:sales,purchases'],
            'status' => ['nullable', 'string', 'in:'.implode(',', [...array_column(DocumentStatus::cases(), 'value'), 'open'])],
            'party_id' => ['nullable', 'string', 'size:26'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'overdue' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer'],
        ]);
        $search = trim($request->string('q')->toString());
        $side = $request->input('side');
        $open = [DocumentStatus::Posted->value, DocumentStatus::PartlyPaid->value];

        $page = $this->books->query(Document::class, $company)
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')->toString()))
            ->when($side !== null, fn ($query) => $query->whereIn('type', array_map(fn (DocumentType $type) => $type->value, array_filter(DocumentType::cases(), fn (DocumentType $type) => $type->isSales() === ($side === 'sales')))))
            ->when($request->input('status') === 'open', fn ($query) => $query->whereIn('status', $open))
            ->when($request->filled('status') && $request->input('status') !== 'open', fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('party_id'), fn ($query) => $query->where('party_id', $request->string('party_id')->toString()))
            ->when($request->filled('from'), fn ($query) => $query->where('issue_date', '>=', $request->string('from')->toString()))
            ->when($request->filled('to'), fn ($query) => $query->where('issue_date', '<=', $request->string('to')->toString()))
            ->when($request->boolean('overdue'), fn ($query) => $query->whereIn('status', $open)->where('due_date', '<', $this->books->today($company)->toDateString()))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('number', 'like', '%'.$search.'%')
                ->orWhere('reference', 'like', '%'.$search.'%')
                ->orWhereIn('party_id', $this->books->query(Party::class, $company)->where('name', 'like', '%'.$search.'%')->select('id'))))
            ->orderByDesc('issue_date')->orderByDesc('created_at')
            ->paginate(PerPage::from($request));

        $names = $this->books->query(Party::class, $company)->whereKey(collect($page->items())->pluck('party_id')->unique()->values()->all())->pluck('name', 'id')->all();

        return response()->json([
            'data' => collect($page->items())->map(fn (Document $document) => $this->presenter->documentItem($document, $names))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(string $organization, string $document): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.view', $company);

        return response()->json(['data' => $this->presenter->document($this->documentIn($company, $document), $company)]);
    }

    public function store(DocumentRequest $request, string $organization): JsonResponse
    {
        $company = $this->company($organization);
        $data = $request->validated();
        $type = DocumentType::from($data['type']);
        Gate::authorize($type->isSales() ? 'accounting.sell' : 'accounting.buy', $company);

        // Written and sent together: either both happen or nothing is saved.
        $document = $this->books->transaction($company, function () use ($company, $type, $data, $request) {
            $document = $this->documents->draft($company, $type, $data, $request->user());

            return ($data['submit'] ?? false) ? $this->documents->submit($company, $document, $document->version, $request->user()) : $document;
        });

        return response()->json(['data' => $this->presenter->document($document, $company)], 201);
    }

    public function update(DocumentRequest $request, string $organization, string $document): JsonResponse
    {
        $company = $this->company($organization);
        $found = $this->documentIn($company, $document);
        Gate::authorize($found->type->isSales() ? 'accounting.sell' : 'accounting.buy', $company);

        $data = $request->validated();
        $updated = $this->documents->update($company, $found, (int) $data['base_version'], array_diff_key($data, ['base_version' => true]), $request->user());

        return response()->json(['data' => $this->presenter->document($updated, $company)]);
    }

    public function destroy(Request $request, string $organization, string $document): JsonResponse
    {
        $company = $this->company($organization);
        $found = $this->documentIn($company, $document);
        Gate::authorize($found->type->isSales() ? 'accounting.sell' : 'accounting.buy', $company);
        $request->validate(['base_version' => ['required', 'integer', 'min:1']]);

        $this->documents->delete($company, $found, $request->integer('base_version'), $request->user());

        return response()->json(null, 204);
    }
}
