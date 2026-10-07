<?php

namespace Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Enums\OrganizationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Crm\Exceptions\CrmException;
use Modules\Crm\Http\Controllers\Concerns\FindsCrm;
use Modules\Crm\Http\CrmPresenter;
use Modules\Crm\Http\Requests\DealRequest;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Deal;
use Modules\Crm\Services\Crm;
use Modules\Crm\Services\Deals;
use Modules\Crm\Services\Pipelines;

/**
 * Deals of the unit in the address and below: the board of one pipeline
 * (crm.view), made, changed and moved between stages (crm.edit).
 */
class DealController extends Controller
{
    use FindsCrm;

    public function __construct(private Crm $crm, private Deals $deals, private CrmPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('crm.view', $unit);
        $filters = $request->validate(['pipeline_id' => ['nullable', 'string', 'max:26'], 'status' => ['nullable', 'in:open,won,lost,all'], 'mine' => ['nullable', 'boolean'], 'contact_id' => ['nullable', 'string', 'max:26']]);
        $pipeline = $filters['pipeline_id'] ?? app(Pipelines::class)->default($company)->getKey();
        $status = $filters['status'] ?? 'open';
        $deals = $this->crm->query(Deal::class, $company)->whereIn('unit_id', $this->unitIds($unit))->where('pipeline_id', $pipeline)
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            // Closed ones: the last 90 days are enough for a board.
            ->when($status !== 'open', fn ($query) => $query->where(fn ($inner) => $inner->whereNull('closed_at')->orWhere('closed_at', '>=', now()->subDays(90))))
            ->when(! empty($filters['mine']), fn ($query) => $query->where('owner_id', $request->user()->getKey()))
            ->when(! empty($filters['contact_id']), fn ($query) => $query->where('contact_id', $filters['contact_id']))
            ->orderBy('expected_on')->orderByDesc('created_at')->limit(500)->get();
        $contacts = $this->crm->query(Contact::class, $company)->whereKey($deals->pluck('contact_id')->unique()->all())->get(['id', 'name', 'company_name'])->keyBy('id');

        return response()->json([
            'data' => $deals->map(fn (Deal $deal) => [...$this->presenter->deal($deal), 'contact_name' => $contacts[$deal->contact_id]->name ?? null, 'contact_company' => $contacts[$deal->contact_id]->company_name ?? null])->values(),
            'meta' => ['pipeline_id' => $pipeline, 'currency' => $this->crm->currency($company)],
        ]);
    }

    public function store(DealRequest $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        if ($unit->type === OrganizationType::Group) {
            throw CrmException::notCompanyUnit();
        }
        Gate::authorize('crm.edit', $unit);
        $data = $request->validated();
        // The contact must be one this unit sees.
        $this->recordIn(Contact::class, $unit, $company, $data['contact_id'], 'contact');

        return response()->json(['data' => $this->presenter->deal($this->deals->create($company, $this->unitFor($unit, $data['unit_id'] ?? null), $data, $request->user()))], 201);
    }

    public function update(DealRequest $request, string $organization, string $deal): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        Gate::authorize('crm.edit', $unit);
        $found = $this->recordIn(Deal::class, $unit, $company, $deal, 'deal');
        $data = $request->validated();

        return response()->json(['data' => $this->presenter->deal($this->deals->update($company, $found, (int) $data['base_version'], $data, $request->user()))]);
    }

    public function move(DealRequest $request, string $organization, string $deal, string $step): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        abort_unless($step === 'move', 404);
        Gate::authorize('crm.edit', $unit);
        $found = $this->recordIn(Deal::class, $unit, $company, $deal, 'deal');
        $data = $request->validated();

        return response()->json(['data' => $this->presenter->deal($this->deals->move($company, $found, (int) $data['base_version'], $data['stage_id'], $data['lost_reason'] ?? null, $request->user()))]);
    }
}
