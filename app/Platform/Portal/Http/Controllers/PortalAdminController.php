<?php

namespace App\Platform\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Notifications\Services\LinkBuilder;
use App\Platform\Portal\Exceptions\PortalException;
use App\Platform\Portal\Http\Requests\PortalDecisionRequest;
use App\Platform\Portal\Http\Requests\PortalInviteRequest;
use App\Platform\Portal\Models\PortalInvitation;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Portal\PortalSubjects;
use App\Platform\Portal\Services\PortalInvitations;
use App\Platform\Portal\Services\PortalLinks;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The client's side of its portal (Phase 5C-4): invite people to records,
 * approve or reject what they asked for, revoke access. Seeing needs
 * client_portal.view, acting client_portal.manage, at the organization.
 */
class PortalAdminController extends Controller
{
    use FindsVisibleOrganizations;

    public function __construct(
        private PortalSubjects $subjects,
        private PortalInvitations $invitations,
        private PortalLinks $links,
    ) {}

    public function show(string $organization): JsonResponse
    {
        $organization = $this->organization($organization, 'client_portal.view');

        $invitations = PortalInvitation::query()
            ->where('organization_id', $organization->getKey())
            ->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', now())
            ->latest()->limit(200)->get();

        // Waiting ones first: they need a decision.
        $links = PortalLink::query()->with('user')
            ->where('organization_id', $organization->getKey())
            ->whereIn('status', [PortalLink::PENDING, PortalLink::ACTIVE])
            ->latest()->limit(500)->get()
            ->sortBy(fn (PortalLink $link) => $link->status === PortalLink::PENDING ? 0 : 1)
            ->values();

        return response()->json(['data' => [
            'kinds' => array_map(fn (string $key) => [
                'key' => $key,
                'label' => $this->subjects->provider($key)->label(),
                'relations' => $this->subjects->provider($key)->relations(),
            ], $this->subjects->available($organization)),
            'invitations' => $invitations->map(fn (PortalInvitation $invitation) => [
                'id' => $invitation->getKey(),
                'kind' => $this->kindLabel($invitation->subject_type),
                'record' => $this->recordName($organization, $invitation->subject_type, $invitation->subject_id),
                'name' => $invitation->name,
                'relation' => $invitation->relation,
                'to' => $this->invitations->masked($invitation),
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ])->values(),
            'links' => $links->map(fn (PortalLink $link) => [
                'id' => $link->getKey(),
                'status' => $link->status,
                'member' => $link->user?->name,
                'kind' => $this->kindLabel($link->subject_type),
                'record' => $this->recordName($organization, $link->subject_type, $link->subject_id),
                'relation' => $link->relation,
                'since' => $link->created_at->toIso8601String(),
            ])->values(),
            'can_manage' => Gate::allows('client_portal.manage', $organization),
        ]]);
    }

    public function search(Request $request, string $organization, string $kind): JsonResponse
    {
        $organization = $this->organization($organization, 'client_portal.manage');
        if (! $this->subjects->usable($kind, $organization)) {
            throw PortalException::kindNotAvailable();
        }

        $term = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $records = $this->subjects->provider($kind)->search($organization, $term);

        return response()->json(['data' => array_map(fn ($subject) => ['id' => $subject->id, 'name' => $subject->name], $records)]);
    }

    public function invite(PortalInviteRequest $request, string $organization, LinkBuilder $links): JsonResponse
    {
        $organization = $this->organization($organization, 'client_portal.manage');
        $result = $this->invitations->create($organization, $request->user(), [...$request->validated(), 'send' => (bool) $request->validated('send', true)]);

        return response()->json(['data' => [
            'id' => $result['invitation']->getKey(),
            // Shown once, to print or read out; only hashes are kept.
            'code' => $result['code'],
            'link' => $links->to("/portal/join/{$result['token']}", $organization->partner, $organization),
            'expires_at' => $result['invitation']->expires_at->toIso8601String(),
        ], 'message' => __('portal.messages.invited')], 201);
    }

    public function revokeInvitation(Request $request, string $organization, string $invitation): JsonResponse
    {
        $organization = $this->organization($organization, 'client_portal.manage');
        $found = PortalInvitation::query()->where('organization_id', $organization->getKey())->whereKey($invitation)->first()
            ?? throw PortalException::invitationNotFound();

        $this->invitations->revoke($found, $request->user());

        return response()->json(['message' => __('portal.messages.invitation_revoked')]);
    }

    public function approve(Request $request, string $organization, string $link): JsonResponse
    {
        $found = $this->link($organization, $link);
        $this->links->approve($found, $request->user());

        return response()->json(['message' => __('portal.messages.approved')]);
    }

    public function reject(PortalDecisionRequest $request, string $organization, string $link): JsonResponse
    {
        $found = $this->link($organization, $link);
        $this->links->reject($found, $request->user(), $request->validated('reason'));

        return response()->json(['message' => __('portal.messages.rejected')]);
    }

    public function revoke(PortalDecisionRequest $request, string $organization, string $link): JsonResponse
    {
        $found = $this->link($organization, $link);
        $this->links->revoke($found, $request->user(), $request->validated('reason'));

        return response()->json(['message' => __('portal.messages.revoked')]);
    }

    private function link(string $organization, string $link): PortalLink
    {
        $organization = $this->organization($organization, 'client_portal.manage');

        return PortalLink::query()->where('organization_id', $organization->getKey())->whereKey($link)->first()
            ?? throw PortalException::linkNotFound();
    }

    private function organization(string $id, string $permission): Organization
    {
        $organization = $this->findVisible($id)->loadMissing('partner');

        Gate::authorize($permission, $organization);

        return $organization;
    }

    private function kindLabel(string $kind): string
    {
        return $this->subjects->has($kind) ? $this->subjects->provider($kind)->label() : $kind;
    }

    private function recordName(Organization $organization, string $kind, string $id): ?string
    {
        return $this->subjects->has($kind) ? $this->subjects->provider($kind)->find($organization, $id)?->name : null;
    }
}
