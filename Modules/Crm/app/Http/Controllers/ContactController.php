<?php

namespace Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Audit\AuditLogger;
use App\Platform\Tenancy\Enums\OrganizationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Services\Customers as BooksCustomers;
use Modules\Crm\Exceptions\CrmException;
use Modules\Crm\Http\Controllers\Concerns\FindsCrm;
use Modules\Crm\Http\CrmPresenter;
use Modules\Crm\Http\Requests\ContactRequest;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Deal;
use Modules\Crm\Models\Quote;
use Modules\Crm\Services\Contacts;
use Modules\Crm\Services\Crm;
use Modules\Crm\Services\Quotes;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Contacts of the unit in the address and the units below: read
 * (crm.view), made and changed (crm.edit), made anonymous on request
 * (crm.manage), exported (crm.export, rate limited and audited), imported
 * (crm.edit; checked first, then made), made a customer in the books
 * (crm.edit and Accounting's accounting.sell).
 */
class ContactController extends Controller
{
    use FindsCrm;

    public function __construct(private Crm $crm, private Contacts $contacts, private CrmPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('crm.view', $unit);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'tag' => ['nullable', 'string', 'max:30'], 'source' => ['nullable', 'string', 'max:40'],
            'mine' => ['nullable', 'boolean'], 'inactive' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = $this->query($request, $unit, $company, $filters)->orderBy('name');
        $page = $query->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (Contact $contact) => $this->presenter->contact($contact))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(string $organization, string $contact): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('crm.view', $unit);
        /** @var Contact $found */
        $found = $this->recordIn(Contact::class, $unit, $company, $contact, 'contact');
        $quotes = app(Quotes::class);
        $books = app(BooksCustomers::class);

        return response()->json(['data' => [
            ...$this->presenter->contact($found),
            'deals' => $this->crm->query(Deal::class, $company)->where('contact_id', $found->getKey())->orderByDesc('created_at')->limit(50)->get()->map(fn (Deal $deal) => $this->presenter->deal($deal))->values(),
            'activities' => $this->crm->query(Activity::class, $company)->where('contact_id', $found->getKey())->orderByDesc('created_at')->limit(100)->get()
                // Open follow-ups first, then the history, newest first.
                ->sortBy(fn (Activity $activity) => $activity->done_at === null ? 0 : 1)->map(fn (Activity $activity) => $this->presenter->activity($activity))->values(),
            'quotes' => $this->crm->query(Quote::class, $company)->where('contact_id', $found->getKey())->orderByDesc('issue_date')->limit(50)->get()->map(fn (Quote $quote) => $this->presenter->quote($quote, $quotes->expired($company, $quote)))->values(),
            'books' => [
                'party_id' => $books->partyOf($company, $found->getKey()),
                'owed_minor' => $books->owedBy($company, $found->getKey()),
                'can_make_customer' => $books->available($company) && Gate::allows('accounting.sell', $company) && Gate::allows('crm.edit', $unit),
            ],
        ]]);
    }

    public function store(ContactRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        if ($unit->type === OrganizationType::Group) {
            throw CrmException::notCompanyUnit();
        }
        Gate::authorize('crm.edit', $unit);
        $made = $this->contacts->create($company, $this->unitFor($unit, $request->validated('unit_id')), $request->validated(), $request->user());

        return response()->json(['data' => $this->presenter->contact($made)], $made->wasRecentlyCreated ? 201 : 200);
    }

    public function update(ContactRequest $request, string $organization, string $contact): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('crm.edit', $unit);
        $found = $this->recordIn(Contact::class, $unit, $company, $contact, 'contact');
        $data = $request->validated();

        return response()->json(['data' => $this->presenter->contact($this->contacts->update($company, $found, (int) $data['base_version'], $data, $request->user()))]);
    }

    public function anonymize(Request $request, string $organization, string $contact): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('crm.manage', $unit);
        $found = $this->recordIn(Contact::class, $unit, $company, $contact, 'contact');
        $data = $request->validate(['base_version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:5', 'max:300']]);

        return response()->json(['data' => $this->presenter->contact($this->contacts->anonymize($company, $found, (int) $data['base_version'], $data['reason'], $request->user()))]);
    }

    /** The contact as a customer in the books (made once; found again after). */
    public function makeCustomer(Request $request, string $organization, string $contact): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('crm.edit', $unit);
        Gate::authorize('accounting.sell', $company);
        /** @var Contact $found */
        $found = $this->recordIn(Contact::class, $unit, $company, $contact, 'contact');
        if ($found->anonymized_at !== null) {
            throw CrmException::anonymized();
        }
        $books = app(BooksCustomers::class);
        if (! $books->available($company)) {
            throw CrmException::accountingOff();
        }
        $party = $books->forContact($company, $found->getKey(), ['name' => $found->company_name ?: $found->name, 'phone' => $found->phone, 'email' => $found->email, 'address' => $found->address], $request->user());

        return response()->json(['data' => ['party_id' => $party]]);
    }

    /** Rows read from a CSV on the screen: checked (commit false) or made (commit true). */
    public function import(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        if ($unit->type === OrganizationType::Group) {
            throw CrmException::notCompanyUnit();
        }
        Gate::authorize('crm.edit', $unit);
        $data = $request->validate([
            'unit_id' => ['nullable', 'string', 'max:26'], 'commit' => ['required', 'boolean'],
            'rows' => ['required', 'array', 'min:1', 'max:'.Contacts::MAX_IMPORT], 'rows.*' => ['array', 'max:60'], 'rows.*.*' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => $this->contacts->import($company, $this->unitFor($unit, $data['unit_id'] ?? null), $data['rows'], (bool) $data['commit'], $request->user())]);
    }

    /** The list as CSV (same filters), audited: who took how many. */
    public function export(Request $request, string $organization): StreamedResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('crm.export', $unit);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'tag' => ['nullable', 'string', 'max:30'], 'source' => ['nullable', 'string', 'max:40'], 'mine' => ['nullable', 'boolean']]);
        $query = $this->query($request, $unit, $company, $filters)->orderBy('name');
        app(AuditLogger::class)->record('crm.contacts_exported', null, new: ['count' => (clone $query)->count(), 'filters' => array_filter($filters)], actor: $request->user(), organizationId: $company->getKey());

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['name', 'company_name', 'phone', 'email', 'tags', 'source', 'sms_consent', 'email_consent', 'spent_minor', 'purchases', 'last_purchase_on', 'points']);
            foreach ($query->cursor() as $contact) {
                // Cells that could start a formula are kept as text.
                $safe = fn (?string $text) => $text !== null && preg_match('/^[=+\-@]/', $text) === 1 ? "'{$text}" : $text;
                fputcsv($out, [$safe($contact->name), $safe($contact->company_name), $contact->phone, $contact->email, implode(';', $contact->tags ?? []), $contact->source,
                    $contact->sms_consent ? 'yes' : 'no', $contact->email_consent ? 'yes' : 'no', $contact->spent_minor, $contact->purchases, $contact->last_purchase_on?->toDateString(), $contact->points]);
            }
            fclose($out);
        }, 'contacts.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(Request $request, $unit, $company, array $filters)
    {
        $query = $this->crm->query(Contact::class, $company)->whereIn('unit_id', $this->unitIds($unit))->whereNull('anonymized_at')
            ->when(empty($filters['inactive']), fn ($query) => $query->where('is_active', true));
        if (! empty($filters['q'])) {
            $needle = trim($filters['q']);
            $phone = $this->contacts->phone($company, $needle);
            $query->where(fn ($inner) => $inner->where('name', 'like', "%{$needle}%")->orWhere('company_name', 'like', "%{$needle}%")
                ->orWhere('email', 'like', '%'.mb_strtolower($needle).'%')->when($phone !== null, fn ($or) => $or->orWhere('phone', $phone))
                ->when($phone === null && preg_match('/^\+?\d{4,}$/', $needle) === 1, fn ($or) => $or->orWhere('phone', 'like', '%'.ltrim($needle, '+0').'%')));
        }
        if (! empty($filters['tag'])) {
            $query->whereJsonContains('tags', $filters['tag']);
        }
        if (! empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }
        if (! empty($filters['mine'])) {
            $query->where('owner_id', $request->user()->getKey());
        }

        return $query;
    }
}
