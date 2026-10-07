<?php

namespace Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Enums\OrganizationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Services\Customers as BooksCustomers;
use Modules\Crm\Exceptions\CrmException;
use Modules\Crm\Http\Controllers\Concerns\FindsCrm;
use Modules\Crm\Http\CrmPresenter;
use Modules\Crm\Http\Requests\QuoteRequest;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Quote;
use Modules\Crm\Services\Crm;
use Modules\Crm\Services\Quotes;

/**
 * Estimates and quotations of the unit in the address and below: read
 * (crm.view), made, changed, sent, accepted, declined, an estimate turned
 * into a quotation, a draft deleted (crm.edit). Accepting with an invoice
 * also needs Accounting's accounting.sell.
 */
class QuoteController extends Controller
{
    use FindsCrm;

    public function __construct(private Crm $crm, private Quotes $quotes, private CrmPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('crm.view', $unit);
        $filters = $request->validate(['kind' => ['nullable', 'in:estimate,quotation'], 'status' => ['nullable', 'string', 'max:10'], 'contact_id' => ['nullable', 'string', 'max:26'], 'q' => ['nullable', 'string', 'max:60']]);
        $query = $this->crm->query(Quote::class, $company)->whereIn('unit_id', $this->unitIds($unit))->orderByDesc('issue_date')->orderByDesc('created_at')->limit(300);
        foreach (['kind', 'status', 'contact_id'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (! empty($filters['q'])) {
            $query->where(fn ($inner) => $inner->where('number', 'like', '%'.$filters['q'].'%')->orWhere('subject', 'like', '%'.$filters['q'].'%'));
        }
        $quotes = $query->get();
        $contacts = $this->crm->query(Contact::class, $company)->whereKey($quotes->pluck('contact_id')->unique()->all())->get(['id', 'name', 'company_name'])->keyBy('id');

        return response()->json(['data' => $quotes->map(fn (Quote $quote) => [
            ...$this->presenter->quote($quote, $this->quotes->expired($company, $quote)),
            'contact_name' => $contacts[$quote->contact_id]->name ?? null, 'contact_company' => $contacts[$quote->contact_id]->company_name ?? null,
        ])->values()]);
    }

    public function show(string $organization, string $quote): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('crm.view', $unit);

        return response()->json(['data' => $this->full($unit, $company, $this->recordIn(Quote::class, $unit, $company, $quote, 'quote'))]);
    }

    public function store(QuoteRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        if ($unit->type === OrganizationType::Group) {
            throw CrmException::notCompanyUnit();
        }
        Gate::authorize('crm.edit', $unit);
        $data = $request->validated();
        $this->recordIn(Contact::class, $unit, $company, $data['contact_id'], 'contact');
        $made = $this->quotes->create($company, $this->unitFor($unit, $data['unit_id'] ?? null), $data, $request->user());

        return response()->json(['data' => $this->full($unit, $company, $made)], 201);
    }

    public function update(QuoteRequest $request, string $organization, string $quote): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('crm.edit', $unit);
        $found = $this->recordIn(Quote::class, $unit, $company, $quote, 'quote');
        $data = $request->validated();
        if (isset($data['contact_id'])) {
            $this->recordIn(Contact::class, $unit, $company, $data['contact_id'], 'contact');
        }

        return response()->json(['data' => $this->full($unit, $company, $this->quotes->update($company, $found, (int) $data['base_version'], $data, $request->user()))]);
    }

    public function destroy(Request $request, string $organization, string $quote): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('crm.edit', $unit);
        $found = $this->recordIn(Quote::class, $unit, $company, $quote, 'quote');
        $request->validate(['base_version' => ['required', 'integer', 'min:1']]);
        $this->quotes->delete($company, $found, (int) $request->input('base_version'), $request->user());

        return response()->json(null, 204);
    }

    public function step(QuoteRequest $request, string $organization, string $quote, string $step): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->recordIn(Quote::class, $unit, $company, $quote, 'quote');
        abort_unless(in_array($step, ['send', 'accept', 'decline', 'convert'], true), 404);
        Gate::authorize('crm.edit', $unit);
        $data = $request->validated();
        $version = (int) $data['base_version'];
        $actor = $request->user();
        if ($step === 'accept' && ! empty($data['invoice'])) {
            Gate::authorize('accounting.sell', $company);
        }

        $changed = match ($step) {
            'send' => $this->quotes->send($company, $found, $version, $actor),
            'accept' => $this->quotes->accept($company, $found, $version, (bool) ($data['invoice'] ?? false), $actor),
            'decline' => $this->quotes->decline($company, $found, $version, (string) $data['reason'], $actor),
            'convert' => $this->quotes->convert($company, $found, $version, $actor),
        };

        return response()->json(['data' => $this->full($unit, $company, $changed)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function full($unit, $company, Quote $quote): array
    {
        $edits = Gate::allows('crm.edit', $unit);
        $open = in_array($quote->status, [Quote::DRAFT, Quote::SENT], true);
        $contact = $this->crm->query(Contact::class, $company)->find($quote->contact_id);

        return [
            ...$this->presenter->quote($quote, $this->quotes->expired($company, $quote), $this->quotes->linesOf($company, $quote), [
                'edit' => $edits && $open,
                'send' => $edits && $open,
                'accept' => $edits && $open && $quote->kind === 'quotation',
                'invoice' => $edits && $open && $quote->kind === 'quotation' && app(BooksCustomers::class)->available($company) && Gate::allows('accounting.sell', $company),
                'decline' => $edits && $open,
                'convert' => $edits && $open && $quote->kind === 'estimate',
                'delete' => $edits && $quote->status === Quote::DRAFT,
            ]),
            'contact' => $contact === null ? null : ['id' => $contact->getKey(), 'name' => $contact->name, 'company_name' => $contact->company_name, 'phone' => $contact->phone, 'email' => $contact->email, 'address' => $contact->address],
            'company' => $company->name,
        ];
    }
}
