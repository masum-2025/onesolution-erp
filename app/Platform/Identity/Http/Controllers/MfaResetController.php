<?php

namespace App\Platform\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Identity\Http\Requests\MfaResetRequest;
use App\Platform\Identity\Models\MfaReset;
use App\Platform\Identity\Services\MfaResets;
use App\Platform\Tenancy\Exceptions\OrganizationNotFound;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * An organization's two-step sign-in resets (Phase 8-1): ask for one for a
 * member, list those waiting, approve or reject (a different admin). All
 * need security.mfa_reset in the organization, and a recent second step.
 */
class MfaResetController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(private MfaResets $resets) {}

    public function index(string $organization): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('security.mfa_reset', $organization);

        return response()->json(['data' => $this->resets->open($organization)->map(fn (MfaReset $reset) => [
            'id' => $reset->getKey(),
            'user' => ['id' => $reset->user->getKey(), 'name' => $reset->user->name, 'email' => $reset->user->email],
            'requested_by' => ['id' => $reset->requester->getKey(), 'name' => $reset->requester->name],
            'reason' => $reset->reason,
            'expires_at' => $reset->expires_at->toIso8601String(),
            'created_at' => $reset->created_at?->toIso8601String(),
        ])->values()]);
    }

    public function store(MfaResetRequest $request, string $organization, string $membership): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('security.mfa_reset', $organization);

        $membership = OrganizationMembership::query()
            ->with('user')
            ->where('organization_id', $organization->getKey())
            ->whereKey($membership)
            ->first() ?? throw new OrganizationNotFound;

        $reset = $this->resets->request($organization, $membership, $request->user(), $request->validated('reason'));

        return response()->json(['data' => ['id' => $reset->getKey()], 'message' => __('two_factor.messages.reset_requested')], 201);
    }

    public function approve(Request $request, string $organization, string $reset): JsonResponse
    {
        $this->resets->approve($this->find($organization, $reset), $request->user());

        return response()->json(['message' => __('two_factor.messages.reset_approved')]);
    }

    public function reject(Request $request, string $organization, string $reset): JsonResponse
    {
        $this->resets->reject($this->find($organization, $reset), $request->user());

        return response()->json(['message' => __('two_factor.messages.reset_rejected')]);
    }

    private function find(string $organization, string $reset): MfaReset
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('security.mfa_reset', $organization);

        return MfaReset::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($reset)
            ->first() ?? throw new OrganizationNotFound;
    }
}
