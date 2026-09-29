<?php

namespace App\Platform\SupportAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\SupportAccess\Exceptions\SupportException;
use App\Platform\SupportAccess\Http\Requests\DecideSupportRequest;
use App\Platform\SupportAccess\Http\Requests\SupportReasonRequest;
use App\Platform\SupportAccess\Http\SupportGrantPresenter;
use App\Platform\SupportAccess\Models\SupportGrant;
use App\Platform\SupportAccess\Services\SupportAccessService;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The client's side of support access: see requests for this organization
 * and the units below it, approve, reject, or end access early.
 */
class ClientSupportController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(private SupportAccessService $support, private SupportGrantPresenter $presenter) {}

    public function index(string $organization): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('support.approve', $organization);

        $grants = SupportGrant::query()
            ->with(['organization', 'partner'])
            ->whereIn('organization_id', Organization::query()->subtreeOf($organization)->select('id'))
            ->latest()
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $this->presenter->many($grants),
            'max_minutes' => $this->support->maxMinutes($organization),
        ]);
    }

    public function approve(DecideSupportRequest $request, string $organization, string $grant): JsonResponse
    {
        $grant = $this->support->approve($this->find($organization, $grant), $request->user(), $request->validated('reason'));

        return response()->json(['data' => $this->presenter->one($grant->load(['organization', 'partner'])), 'message' => __('support.messages.approved')]);
    }

    public function reject(SupportReasonRequest $request, string $organization, string $grant): JsonResponse
    {
        $grant = $this->support->reject($this->find($organization, $grant), $request->user(), $request->validated('reason'));

        return response()->json(['data' => $this->presenter->one($grant->load(['organization', 'partner'])), 'message' => __('support.messages.rejected')]);
    }

    public function revoke(SupportReasonRequest $request, string $organization, string $grant): JsonResponse
    {
        $grant = $this->support->revoke($this->find($organization, $grant), $request->user(), $request->validated('reason'));

        return response()->json(['data' => $this->presenter->one($grant->load(['organization', 'partner'])), 'message' => __('support.messages.revoked')]);
    }

    private function find(string $organizationId, string $grantId): SupportGrant
    {
        $organization = $this->findVisible($organizationId);
        Gate::authorize('support.approve', $organization);

        return SupportGrant::query()
            ->whereKey($grantId)
            ->whereIn('organization_id', Organization::query()->subtreeOf($organization)->select('id'))
            ->first() ?? throw SupportException::notFound();
    }
}
