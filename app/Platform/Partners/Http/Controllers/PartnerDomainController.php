<?php

namespace App\Platform\Partners\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Partners\Exceptions\PartnerException;
use App\Platform\Partners\Http\Controllers\Concerns\PartnerConsole;
use App\Platform\Partners\Http\Requests\PartnerReasonRequest;
use App\Platform\Partners\Http\Requests\StoreDomainRequest;
use App\Platform\Partners\Models\PartnerDomain;
use App\Platform\Partners\Services\DomainService;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Http\JsonResponse;

/**
 * Partner console: custom domains and their DNS verification.
 */
class PartnerDomainController extends Controller
{
    use PartnerConsole;

    public function __construct(private DomainService $domains) {}

    public function index(): JsonResponse
    {
        $domains = PartnerDomain::query()
            ->with('organization')
            ->where('partner_id', $this->partner()->getKey())
            ->orderBy('host')
            ->get();

        return response()->json(['data' => $domains->map(fn (PartnerDomain $domain) => $this->present($domain))->values()]);
    }

    public function store(StoreDomainRequest $request): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);

        $client = $request->validated('organization_id') ? $this->client($request->validated('organization_id')) : null;
        $domain = $this->domains->add($this->partner(), $request->validated('host'), $client, $request->user());

        return response()->json(['data' => $this->present($domain->load('organization')), 'message' => __('partners.messages.domain_added')], 201);
    }

    // The service is resolved per call so the DNS lookup in use is always the current one.
    public function verify(string $domain, DomainService $domains): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        $domain = $domains->verify($this->find($domain), request()->user());

        return response()->json(['data' => $this->present($domain->load('organization')), 'message' => __('partners.messages.domain_verified')]);
    }

    public function destroy(PartnerReasonRequest $request, string $domain): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        $this->domains->remove($this->find($domain), $request->user(), $request->validated('reason'));

        return response()->json(['message' => __('partners.messages.domain_removed')]);
    }

    private function find(string $id): PartnerDomain
    {
        return PartnerDomain::query()
            ->where('partner_id', $this->partner()->getKey())
            ->whereKey($id)
            ->first() ?? throw PartnerException::domainNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PartnerDomain $domain): array
    {
        return [
            'id' => $domain->getKey(),
            'host' => $domain->host,
            'status' => $domain->status->value,
            'client' => $domain->organization === null ? null : ['id' => $domain->organization->getKey(), 'name' => $domain->organization->displayName()],
            // What to publish in DNS (the token is only shown to the partner that owns the domain).
            'record' => ['type' => 'TXT', 'name' => $domain->txtName(), 'value' => $domain->txtValue()],
            'verified_at' => $domain->verified_at?->toIso8601String(),
            'last_checked_at' => $domain->last_checked_at?->toIso8601String(),
        ];
    }
}
