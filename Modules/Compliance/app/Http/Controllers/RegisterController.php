<?php

namespace Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Compliance\Exceptions\ComplianceException;
use Modules\Compliance\Http\CompliancePresenter;
use Modules\Compliance\Http\Controllers\Concerns\FindsPlace;
use Modules\Compliance\Http\Requests\AuditRequest;
use Modules\Compliance\Http\Requests\DatesRequest;
use Modules\Compliance\Models\Audit;
use Modules\Compliance\Models\ProposedResult;
use Modules\Compliance\Services\Lists;
use Modules\Compliance\Services\Place;
use Modules\Compliance\Services\Quotes;
use Modules\Compliance\Services\Register;
use Modules\Compliance\Services\Reminders;
use Modules\Compliance\Services\Renewals;
use Modules\Compliance\Services\Results;
use Modules\Compliance\Support\Validity;

/**
 * The audit register at the unit in the address and below: every factory
 * and audit service with its days left. Reading needs compliance.view;
 * adding and changing details compliance.manage at the factory; the
 * official dates compliance.set_expiry there.
 */
class RegisterController extends Controller
{
    use FindsPlace;

    /** @var array<string, CarbonImmutable> */
    private array $todays = [];

    public function __construct(
        private Place $place,
        private Lists $lists,
        private Register $register,
        private CompliancePresenter $presenter,
        private Renewals $renewals,
        private Results $results,
    ) {}

    /** What the screens choose from here, and what the rules say. */
    public function options(string $organization, Reminders $reminders): JsonResponse
    {
        $unit = $this->unit($organization, 'compliance.view');
        $writes = $unit->type->value !== 'group' && app(CurrentContext::class)->canWriteTo($unit->getKey());

        return response()->json(['data' => [
            'factories' => $this->place->factories($unit)->map(fn (Organization $factory) => [
                'id' => $factory->getKey(), 'name' => $factory->displayName(), 'type' => $factory->type->value, 'parent_id' => $factory->parent_id, 'depth' => $factory->depth,
            ])->values(),
            'services' => $this->lists->of($unit, 'services')->map(fn ($item) => $this->presenter->item('services', $item, $unit))->values(),
            'types' => $this->lists->of($unit, 'types')->map(fn ($item) => $this->presenter->item('types', $item, $unit))->values(),
            'providers' => $this->lists->of($unit, 'providers')->map(fn ($item) => $this->presenter->item('providers', $item, $unit))->values(),
            'expiring_days' => $this->register->expiringDays($unit),
            'reminder_days' => $reminders->daysFor($unit),
            'today' => $this->place->today($this->clockOf($unit))->toDateString(),
            'can' => [
                // Lines are written at a company (or its branch): a group reads them.
                'add' => $writes && Gate::allows('compliance.manage', $unit),
                'dates' => $writes && Gate::allows('compliance.set_expiry', $unit),
                'lists' => in_array($unit->type->value, ['group', 'company'], true) && Gate::allows('compliance.manage', $unit),
                'import' => $writes && Gate::allows('compliance.manage', $unit) && Gate::allows('compliance.set_expiry', $unit),
            ],
            'reads_only' => ! $writes,
        ]]);
    }

    public function index(Request $request, string $organization): JsonResponse
    {
        $unit = $this->unit($organization, 'compliance.view');
        $filters = $request->validate([
            'unit_id' => ['nullable', 'string', 'size:26'],
            'service_id' => ['nullable', 'string', 'size:26'],
            'state' => ['nullable', 'in:'.implode(',', Validity::STATES)],
        ]);
        $at = $this->unitInside($unit, $filters['unit_id'] ?? null);
        $expiring = $this->register->expiringDays($unit);
        $audits = $this->register->withLists($this->register->lines($at))
            ->when($filters['service_id'] ?? null, fn ($lines, $service) => $lines->where('service_id', $service));
        // Renewals (CMP-2): the latest request of each line and the results waiting, read once for the list.
        $ids = $audits->pluck('id')->values()->all();
        $requests = $this->renewals->latestOf($at, $ids);
        $pending = $this->results->pendingByAudit($at, $ids);

        $lines = $audits
            ->map(fn (Audit $audit) => [
                ...$this->presenter->line($audit, $this->todayOf($audit->company_id), $expiring),
                'renewal' => ($request = $requests->get($audit->getKey())) === null ? null : ['id' => $request->getKey(), 'status' => $request->status, 'planned_on' => $request->planned_on?->toDateString()],
                'results_pending' => $pending[$audit->getKey()] ?? 0,
            ])
            ->when($filters['state'] ?? null, fn ($lines, $state) => $lines->where('state', $state))
            // Most urgent first: expired, then fewest days left; unknown last.
            ->sortBy(fn (array $line) => $line['days_left'] ?? PHP_INT_MAX)
            ->values();

        return response()->json([
            'data' => $lines,
            'meta' => ['summary' => collect(Validity::STATES)->mapWithKeys(fn (string $state) => [$state => $lines->where('state', $state)->count()])],
        ]);
    }

    public function store(AuditRequest $request, string $organization): JsonResponse
    {
        $unit = $this->unit($organization, 'compliance.view');
        $factory = $this->unitInside($unit, $request->validated('unit_id'));
        Gate::authorize('compliance.manage', $factory);
        $audit = $this->register->add($factory, $request->validated(), Gate::allows('compliance.set_expiry', $factory), $request->user());

        return response()->json(['data' => $this->detail($unit, $audit)], 201);
    }

    public function show(string $organization, string $audit): JsonResponse
    {
        $unit = $this->unit($organization, 'compliance.view');

        return response()->json(['data' => $this->detail($unit, $this->register->find($unit, $audit))]);
    }

    public function update(AuditRequest $request, string $organization, string $audit): JsonResponse
    {
        $unit = $this->unit($organization, 'compliance.view');
        $found = $this->register->find($unit, $audit);
        Gate::authorize('compliance.manage', Organization::query()->findOrFail($found->organization_id));
        $changed = $this->register->update($unit, $found, (int) $request->validated('base_version'), $request->validated(), $request->user());

        return response()->json(['data' => $this->detail($unit, $changed)]);
    }

    public function dates(DatesRequest $request, string $organization, string $audit): JsonResponse
    {
        $unit = $this->unit($organization, 'compliance.view');
        $found = $this->register->find($unit, $audit);
        if (! Gate::allows('compliance.set_expiry', Organization::query()->findOrFail($found->organization_id))) {
            throw ComplianceException::datesNeedPermission();
        }
        $changed = $this->register->setDates($unit, $found, (int) $request->validated('base_version'), $request->validated('last_audit_on'),
            $request->validated('expires_on'), $request->validated('reason'), $request->user());

        return response()->json(['data' => $this->detail($unit, $changed)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Organization $unit, Audit $audit): array
    {
        $this->register->withLists($audit);
        $history = $this->register->history($unit, $audit);
        $users = User::query()->whereKey($history->pluck('user_id')->filter()->unique()->all())->pluck('name', 'id')->all();

        $request = $this->renewals->latestOf($unit, [$audit->getKey()])->first();
        $request?->load(['provider' => fn ($query) => $query->withoutGlobalScope(OrganizationScope::class)]);
        $results = $this->results->pendingOf($unit, $audit);

        return [
            ...$this->presenter->line($audit, $this->todayOf($audit->company_id), $this->register->expiringDays($unit)),
            'history' => $history->map(fn ($change) => $this->presenter->change($change, $users))->values(),
            // Renewals (CMP-2): the latest request with its answer, and results waiting to be accepted.
            'renewal' => $request === null ? null : $this->presenter->request($request),
            'results' => $results->map(fn (ProposedResult $result) => $this->presenter->result($result, $audit))->values(),
            // The provider's quotations for this renewal (newest first).
            'quotes' => $request === null ? [] : app(Quotes::class)->forRequest($unit, $request->getKey())->map(fn ($quote) => $this->presenter->quote($quote))->values(),
        ];
    }

    private function todayOf(string $companyId): CarbonImmutable
    {
        return $this->todays[$companyId] ??= $this->place->today(Organization::query()->findOrFail($companyId));
    }
}
