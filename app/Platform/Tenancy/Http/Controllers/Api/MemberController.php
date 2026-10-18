<?php

namespace App\Platform\Tenancy\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Access\Http\Requests\UpdateMemberRolesRequest;
use App\Platform\Access\Services\RoleService;
use App\Platform\Invitations\Services\InvitationService;
use App\Platform\Support\Http\PerPage;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Actions\ChangeMembership;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Exceptions\OrganizationNotFound;
use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Http\Requests\InviteMemberRequest;
use App\Platform\Tenancy\Http\Requests\StoreMemberRequest;
use App\Platform\Tenancy\Http\Requests\UpdateMemberRequest;
use App\Platform\Tenancy\Http\Resources\MembershipResource;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MemberController extends Controller
{
    use FindsVisibleOrganizations;

    public function index(Request $request, string $organization): AnonymousResourceCollection
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('manageMembers', $organization);

        $perPage = PerPage::from($request, 50);
        $page = $organization->memberships()->with(['user', 'roles'])->orderBy('created_at')->paginate($perPage);
        // Who has not set their password yet, and until when their link works.
        $pending = app(InvitationService::class)->pending($organization, collect($page->items())->pluck('user_id')->all());
        foreach ($page->items() as $membership) {
            $membership->setAttribute('pending_invitation', $pending[$membership->user_id] ?? null);
        }

        return MembershipResource::collection($page);
    }

    /**
     * Invite someone by email (no sign-up for them): a new account gets a link
     * to set a password, valid for InvitationService::INVITE_HOURS; an
     * existing one just gets access. Roles are given at once when asked.
     */
    public function invite(InviteMemberRequest $request, string $organization, InvitationService $invitations, RoleService $roles): JsonResponse
    {
        $organization = $this->findVisible($organization);
        $type = MembershipType::from($request->validated('membership_type'));
        Gate::authorize($type === MembershipType::Owner ? 'manageOwnership' : 'manageMembers', $organization);

        $result = DB::transaction(function () use ($request, $organization, $type, $invitations, $roles) {
            $result = $invitations->invite($organization, $request->validated('email'), $request->validated('name'), $type,
                AccessScope::from($request->validated('access_scope', AccessScope::Own->value)), $request->user());
            if ($request->validated('role_ids', []) !== []) {
                $roles->syncMembershipRoles($result['membership'], $request->validated('role_ids'), $request->user(), 'Given with the invitation');
            }

            return $result;
        });
        $membership = $result['membership']->load(['user', 'roles']);
        $membership->setAttribute('pending_invitation', $invitations->pending($organization, [$membership->user_id])[$membership->user_id] ?? null);

        return (new MembershipResource($membership))->additional(['meta' => ['invited' => $result['invited']]])->response()->setStatusCode(201);
    }

    /** A new link for a member who has not set their password yet; the old link stops working. */
    public function resendInvite(Request $request, string $organization, string $membership, InvitationService $invitations): JsonResponse
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('manageMembers', $organization);
        $membership = $this->findMembership($organization, $membership);
        if (! $invitations->resend($organization, $membership->user, $request->user())) {
            throw ValidationException::withMessages(['membership' => __('tenancy.errors.invitation_not_pending')]);
        }
        $membership->setAttribute('pending_invitation', $invitations->pending($organization, [$membership->user_id])[$membership->user_id] ?? null);

        return (new MembershipResource($membership->load(['user', 'roles'])))->response();
    }

    public function store(StoreMemberRequest $request, string $organization, AddMember $add): JsonResponse
    {
        $organization = $this->findVisible($organization);
        $type = MembershipType::from($request->validated('membership_type'));
        Gate::authorize($type === MembershipType::Owner ? 'manageOwnership' : 'manageMembers', $organization);

        $user = User::query()->where('email', $request->validated('email'))->first()
            ?? throw ValidationException::withMessages(['email' => __('tenancy.errors.user_not_found')]);

        $membership = $add->handle(
            organization: $organization,
            user: $user,
            type: $type,
            accessScope: AccessScope::from($request->validated('access_scope', AccessScope::Own->value)),
            actor: $request->user(),
        );

        return (new MembershipResource($membership->load(['user', 'roles'])))->response()->setStatusCode(201);
    }

    public function update(UpdateMemberRequest $request, string $organization, string $membership, ChangeMembership $change): MembershipResource
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('manageMembers', $organization);
        $membership = $this->findMembership($organization, $membership);

        if ($membership->isOwner() || $request->validated('membership_type') === MembershipType::Owner->value) {
            Gate::authorize('manageOwnership', $organization);
        }

        return new MembershipResource(
            $change->handle($membership, $request->validated(), $request->user())->load(['user', 'roles'])
        );
    }

    /**
     * Replace the roles a member holds (anti-escalation and separation of
     * duties are checked by RoleService).
     */
    public function updateRoles(UpdateMemberRolesRequest $request, string $organization, string $membership, RoleService $roles): MembershipResource
    {
        $organization = $this->findVisible($organization);
        Gate::authorize('manageMembers', $organization);
        $membership = $this->findMembership($organization, $membership);

        $roles->syncMembershipRoles($membership, $request->validated('role_ids'), $request->user(), $request->validated('reason'));

        return new MembershipResource($membership->load(['user', 'roles']));
    }

    private function findMembership(Organization $organization, string $membership): OrganizationMembership
    {
        return OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->whereKey($membership)
            ->first() ?? throw new OrganizationNotFound;
    }
}
