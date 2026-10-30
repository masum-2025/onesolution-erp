<?php

namespace Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Exceptions\OrganizationAccessDenied;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Compliance\Http\CompliancePresenter;
use Modules\Compliance\Http\Controllers\Concerns\FindsPlace;
use Modules\Compliance\Http\Requests\DecisionRequest;
use Modules\Compliance\Http\Requests\RenewalRequestForm;
use Modules\Compliance\Models\Audit;
use Modules\Compliance\Models\ProposedResult;
use Modules\Compliance\Services\Register;
use Modules\Compliance\Services\Renewals;
use Modules\Compliance\Services\Results;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Renewals with the providers (CMP-2) at the unit in the address and below:
 * requests sent and their answers, and the results providers sent. Reading
 * needs compliance.view; asking and cancelling compliance.request at the
 * factory; accepting or rejecting a result compliance.confirm there. A
 * group reads; its companies write.
 */
class RenewalController extends Controller
{
    use FindsPlace;

    public function __construct(
        private Register $register,
        private Renewals $renewals,
        private Results $results,
        private CompliancePresenter $presenter,
    ) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        $unit = $this->unit($organization, 'compliance.view');
        $open = $request->boolean('open');
        $requests = $this->renewals->list($unit, $open);
        $results = $this->results->list($unit, $open);
        $audits = $this->audits($unit, $results->pluck('audit_id')->filter()->unique()->values()->all());
        $this->presenter->loadDeliveries($requests->pluck('message_id')->filter()->values()->all());
        $lines = $this->audits($unit, $requests->pluck('audit_id')->unique()->values()->all());

        return response()->json(['data' => [
            'requests' => $requests->map(fn ($item) => [
                ...$this->presenter->request($item),
                'service' => ($line = $lines->get($item->audit_id))?->service === null ? null : ['code' => $line->service->code, 'name' => $line->service->textIn('name')],
                'expires_on' => $line?->expires_on?->toDateString(),
            ])->values(),
            'results' => $results->map(fn (ProposedResult $item) => $this->presenter->result($item, $audits->get($item->audit_id)))->values(),
        ]]);
    }

    /** Ask the line's provider to renew it; a provider not on the platform gets the prepared email text. */
    public function store(RenewalRequestForm $request, string $organization, string $audit): JsonResponse
    {
        $unit = $this->unit($organization, 'compliance.view');
        $line = $this->register->find($unit, $audit);
        $this->writableFactory($line->organization_id, 'compliance.request');
        $made = $this->renewals->request($line, $request->validated('wanted_by'), $request->validated('note'), $request->user());
        $made->load(['provider' => fn ($query) => $query->withoutGlobalScope(OrganizationScope::class)]);

        return response()->json(['data' => [
            ...$this->presenter->request($made),
            'mail' => $made->connection_id === null ? $this->renewals->mail($made, $this->register->withLists($line), $request->user()) : null,
        ]], 201);
    }

    public function cancel(DecisionRequest $request, string $organization, string $renewal): JsonResponse
    {
        $unit = $this->unit($organization, 'compliance.view');
        $found = $this->renewals->find($unit, $renewal);
        $this->writableFactory($found->organization_id, 'compliance.request');
        $done = $this->renewals->cancel($unit, $found, (int) $request->validated('base_version'), $request->validated('reason'), $request->user());
        $done->load(['provider' => fn ($query) => $query->withoutGlobalScope(OrganizationScope::class)]);

        return response()->json(['data' => $this->presenter->request($done)]);
    }

    /** Accept a provider's result into the register, or reject it with a reason. */
    public function decide(DecisionRequest $request, string $organization, string $result, string $step): JsonResponse
    {
        abort_unless(in_array($step, ['apply', 'reject'], true), 404);
        $unit = $this->unit($organization, 'compliance.view');
        $found = $this->results->find($unit, $result);
        $this->writableFactory($found->organization_id, 'compliance.confirm');
        $version = (int) $request->validated('base_version');
        $done = $step === 'apply'
            ? $this->results->apply($unit, $found, $version, $request->user())
            : $this->results->reject($unit, $found, $version, (string) $request->validated('reason'), $request->user());

        return response()->json(['data' => $this->presenter->result($done, $this->audits($unit, array_filter([$done->audit_id]))->first())]);
    }

    /** One file of a result: only through a short-lived signed link from the renewals screen. */
    public function file(Request $request, string $organization, string $result, string $index): StreamedResponse
    {
        $found = ProposedResult::inTenantOf($organization)->withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization)->whereKey($result)->first();
        $file = $found === null ? null : (array) ($found->files[(int) $index] ?? []);
        abort_if($file === null || $file === [] || ! ctype_digit($index) || ! Storage::disk('local')->exists((string) ($file['path'] ?? '')), 404);

        $extension = Str::lower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        // A safe file name (names come from the other side).
        $name = (Str::slug(pathinfo((string) $file['name'], PATHINFO_FILENAME)) ?: 'file').($extension === '' ? '' : '.'.preg_replace('/[^a-z0-9]/', '', $extension));

        return Storage::disk('local')->download((string) $file['path'], $name, [
            'Content-Type' => (string) ($file['mime'] ?? 'application/octet-stream'),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** The line's factory: written from here (a group reads only) and allowed there. */
    private function writableFactory(string $factoryId, string $permission): Organization
    {
        $factory = Organization::query()->findOrFail($factoryId);
        if (! app(CurrentContext::class)->canWriteTo($factory->getKey())) {
            throw OrganizationAccessDenied::writeOutsideScope();
        }
        Gate::authorize($permission, $factory);

        return $factory;
    }

    /**
     * Register lines by id, with their lists.
     *
     * @param  list<string>  $ids
     * @return \Illuminate\Support\Collection<string, Audit>
     */
    private function audits(Organization $unit, array $ids): \Illuminate\Support\Collection
    {
        if ($ids === []) {
            return collect();
        }
        $found = $this->register->lines($unit)->whereIn('id', $ids)->values();

        return $this->register->withLists($found)->keyBy('id');
    }
}
