<?php

namespace Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Support\Http\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Http\AccountingPresenter;
use Modules\Accounting\Http\Controllers\Concerns\FindsBooks;
use Modules\Accounting\Http\Requests\JournalRequest;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\Journals;

/**
 * Journal entries: the list (newest first, filtered), one entry with its
 * lines, writing and changing drafts, removing a draft. Sending, approving
 * and reversing are steps (JournalStepController).
 */
class JournalController extends Controller
{
    use FindsBooks;

    public function __construct(private Books $books, private Journals $journals, private AccountingPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.view', $company);

        $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(JournalStatus::cases(), 'value'))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'account_id' => ['nullable', 'string', 'size:26'],
            'source_module' => ['nullable', 'string', 'max:50'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer'],
        ]);
        $search = trim($request->string('q')->toString());

        $page = $this->books->query(Journal::class, $company)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('from'), fn ($query) => $query->where('entry_date', '>=', $request->string('from')->toString()))
            ->when($request->filled('to'), fn ($query) => $query->where('entry_date', '<=', $request->string('to')->toString()))
            ->when($request->filled('source_module'), fn ($query) => $query->where('source_module', $request->string('source_module')->toString()))
            ->when($request->filled('account_id'), fn ($query) => $query->whereIn('id', $this->books->query(JournalLine::class, $company)
                ->where('account_id', $request->string('account_id')->toString())->select('journal_id')))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('number', 'like', '%'.$search.'%')
                ->orWhere('narration', 'like', '%'.$search.'%')))
            ->orderByDesc('entry_date')->orderByDesc('created_at')
            ->paginate(PerPage::from($request));

        return response()->json([
            'data' => collect($page->items())->map(fn (Journal $journal) => $this->presenter->listItem($journal))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(string $organization, string $journal): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.view', $company);

        return response()->json(['data' => $this->presenter->journal($this->journalIn($company, $journal), $company)]);
    }

    public function store(JournalRequest $request, string $organization): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.post', $company);
        $data = $request->validated();

        // Written and sent together: either both happen or nothing is saved.
        $journal = $this->books->transaction($company, function () use ($company, $data, $request) {
            $journal = $this->journals->draft($company, $data, $request->user());

            return ($data['submit'] ?? false) ? $this->journals->submit($company, $journal, $journal->version, $request->user()) : $journal;
        });

        return response()->json(['data' => $this->presenter->journal($journal, $company)], 201);
    }

    public function update(JournalRequest $request, string $organization, string $journal): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.post', $company);
        $found = $this->journalIn($company, $journal);

        $data = $request->validated();
        $updated = $this->journals->update($company, $found, (int) $data['base_version'], array_diff_key($data, ['base_version' => true]), $request->user());

        return response()->json(['data' => $this->presenter->journal($updated, $company)]);
    }

    public function destroy(Request $request, string $organization, string $journal): JsonResponse
    {
        $company = $this->company($organization);
        Gate::authorize('accounting.post', $company);
        $found = $this->journalIn($company, $journal);
        $request->validate(['base_version' => ['required', 'integer', 'min:1']]);

        $this->journals->delete($company, $found, $request->integer('base_version'), $request->user());

        return response()->json(null, 204);
    }
}
