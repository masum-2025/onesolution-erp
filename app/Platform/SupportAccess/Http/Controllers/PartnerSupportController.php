<?php

namespace App\Platform\SupportAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\SupportAccess\Enums\Severity;
use App\Platform\SupportAccess\Exceptions\SupportException;
use App\Platform\SupportAccess\Http\Requests\RequestSupportRequest;
use App\Platform\SupportAccess\Http\Requests\SupportReasonRequest;
use App\Platform\SupportAccess\Http\SupportGrantPresenter;
use App\Platform\SupportAccess\Models\SupportGrant;
use App\Platform\SupportAccess\Services\SupportAccessService;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Exceptions\OrganizationNotFound;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;

/**
 * Partner console: ask a client for support access, follow the request,
 * withdraw it. Owners see every request of the partner; support staff
 * their own. Using approved access is a separate step (session context).
 */
class PartnerSupportController extends Controller
{
    public function __construct(
        private CurrentContext $context,
        private SupportAccessService $support,
        private SupportGrantPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        $query = SupportGrant::query()
            ->with(['organization', 'partner'])
            ->where('partner_id', $this->context->partner()->getKey())
            ->latest()
            ->limit(100);

        if (! $this->isOwner()) {
            $query->where('requested_by', $this->context->user()->getKey());
        }

        return response()->json(['data' => $this->presenter->many($query->get())]);
    }

    public function store(RequestSupportRequest $request): JsonResponse
    {
        $this->requireSupportRole();

        // Only this partner's clients; anything else is the same 404.
        $organization = Organization::query()
            ->where('partner_id', $this->context->partner()->getKey())
            ->whereKey($request->validated('organization_id'))
            ->first() ?? throw new OrganizationNotFound;

        $grant = $this->support->request(
            $this->context->partner(),
            $organization,
            $request->user(),
            $request->validated('reason'),
            Severity::from($request->validated('severity')),
            (int) $request->validated('minutes'),
        );

        return response()->json([
            'data' => $this->presenter->one($grant->load(['organization', 'partner'])),
            'message' => __($grant->auto_approved ? 'support.messages.auto_approved' : 'support.messages.requested'),
        ], 201);
    }

    /**
     * Withdraw a request, or leave access early.
     */
    public function cancel(SupportReasonRequest $request, string $grant): JsonResponse
    {
        $grant = SupportGrant::query()
            ->where('partner_id', $this->context->partner()->getKey())
            ->where('requested_by', $request->user()->getKey())
            ->whereKey($grant)
            ->first() ?? throw SupportException::notFound();

        $grant = $this->support->revoke($grant, $request->user(), $request->validated('reason'));

        return response()->json(['data' => $this->presenter->one($grant->load(['organization', 'partner'])), 'message' => __('support.messages.revoked')]);
    }

    private function isOwner(): bool
    {
        return $this->context->partnerUser()->role === PartnerUserRole::Owner;
    }

    private function requireSupportRole(): void
    {
        if (! in_array($this->context->partnerUser()->role, [PartnerUserRole::Owner, PartnerUserRole::Support], true)) {
            throw SupportException::roleNotAllowed();
        }
    }
}
