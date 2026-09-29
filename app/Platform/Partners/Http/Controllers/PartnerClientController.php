<?php

namespace App\Platform\Partners\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Packaging\Services\UsageLimiter;
use App\Platform\Partners\Http\Controllers\Concerns\PartnerConsole;
use App\Platform\Partners\Http\Requests\ClientLimitsRequest;
use App\Platform\Partners\Http\Requests\ClientStatusRequest;
use App\Platform\Partners\Http\Requests\StoreClientRequest;
use App\Platform\Partners\Services\ClientProvisioner;
use App\Platform\Partners\Services\PartnerClientService;
use App\Platform\Tenancy\Enums\OrganizationStatus;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Http\Resources\PartnerOrganizationResource;
use Illuminate\Http\JsonResponse;

/**
 * Partner console: client accounts. Metadata, plan, status and limits only;
 * never the clients' business data.
 */
class PartnerClientController extends Controller
{
    use PartnerConsole;

    public function __construct(private PartnerClientService $clients, private UsageLimiter $limits) {}

    public function store(StoreClientRequest $request): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner, PartnerUserRole::Sales);

        $created = app(ClientProvisioner::class)->create($this->partner(), $request->validated(), $request->user());

        $added = ($request->validated('structure') ?? null) === PartnerClientService::EXISTING_GROUP;

        return response()->json([
            'data' => new PartnerOrganizationResource($created['client']),
            'company' => new PartnerOrganizationResource($created['company']),
            'branches' => PartnerOrganizationResource::collection($created['branches']),
            'owner_invited' => $created['owner_invited'],
            'message' => $added
                ? __('partners.messages.company_added', ['name' => $created['company']->displayName(), 'group' => $created['client']->displayName()])
                : __('partners.messages.client_created', ['name' => $created['company']->displayName()]),
        ], 201);
    }

    public function status(ClientStatusRequest $request, string $client): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);

        $updated = $this->clients->setStatus(
            $this->client($client),
            OrganizationStatus::from($request->validated('status')),
            $request->validated('reason'),
            $request->user(),
        );

        return response()->json([
            'data' => new PartnerOrganizationResource($updated),
            'message' => __($updated->status === OrganizationStatus::Active ? 'partners.messages.client_reactivated' : 'partners.messages.client_suspended'),
        ]);
    }

    /**
     * Limits in use, the plan's values, and the client's own deal.
     */
    public function limits(string $client): JsonResponse
    {
        $client = $this->client($client);

        return response()->json(['data' => [
            'deal' => $this->clients->dealOf($client),
            'effective' => $this->limits->limits($client),
            'usage' => $this->limits->usage($client),
            'can_edit' => $this->hasRole(PartnerUserRole::Owner, PartnerUserRole::Billing),
        ]]);
    }

    public function updateLimits(ClientLimitsRequest $request, string $client): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner, PartnerUserRole::Billing);
        $client = $this->client($client);

        $this->clients->setLimits($client, $request->validated('limits'), $request->validated('reason'), $request->user());

        return response()->json(['data' => [
            'deal' => $this->clients->dealOf($client),
            'effective' => $this->limits->limits($client),
            'usage' => $this->limits->usage($client),
        ], 'message' => __('partners.messages.limits_saved')]);
    }
}
