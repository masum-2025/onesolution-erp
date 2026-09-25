<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\OrganizationMembership;

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->owner = createMember($this->w->c1, MembershipType::Owner);
    $this->token = orgToken($this->owner, $this->w->c1);
});

it('adds an existing user as a member and audits it', function () {
    $user = User::factory()->create(['email' => 'teacher@example.test']);

    $this->asToken($this->token)->postJson("/api/organizations/{$this->w->b1->id}/members", [
        'email' => 'teacher@example.test',
        'membership_type' => 'staff',
    ])->assertCreated()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.membership_type', 'staff');

    expect(AuditLog::where('action', 'membership.added')->where('actor_user_id', $this->owner->id)->exists())->toBeTrue();
});

it('explains what to do when the email has no account', function () {
    $this->asToken($this->token)->postJson("/api/organizations/{$this->w->b1->id}/members", [
        'email' => 'nobody@example.test',
        'membership_type' => 'staff',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'Ask the person to sign up first']);
});

it('rejects adding the same person twice', function () {
    $this->asToken($this->token)->postJson("/api/organizations/{$this->w->c1->id}/members", [
        'email' => $this->owner->email,
        'membership_type' => 'staff',
    ])->assertUnprocessable()->assertJsonPath('code', 'already_member');
});

it('cannot manage members of another company', function () {
    $this->asToken($this->token)->getJson("/api/organizations/{$this->w->c2->id}/members")->assertNotFound();
});

it('forbids non-owners from managing members', function () {
    $staff = createMember($this->w->c1, MembershipType::Staff);

    $this->asToken(orgToken($staff, $this->w->c1))->getJson("/api/organizations/{$this->w->c1->id}/members")
        ->assertForbidden();
});

it('suspends another member and audits it', function () {
    $staff = createMember($this->w->c1, MembershipType::Staff);
    $membership = OrganizationMembership::where('user_id', $staff->id)->sole();

    $this->asToken($this->token)->patchJson("/api/organizations/{$this->w->c1->id}/members/{$membership->id}", [
        'status' => 'suspended',
    ])->assertOk()->assertJsonPath('data.status', 'suspended');

    $audit = AuditLog::where('action', 'membership.changed')->sole();
    expect($audit->old_values)->toBe(['status' => 'active'])
        ->and($audit->new_values)->toBe(['status' => 'suspended']);
});

it('does not let you change your own membership', function () {
    $membership = OrganizationMembership::where('user_id', $this->owner->id)->sole();

    $this->asToken($this->token)->patchJson("/api/organizations/{$this->w->c1->id}/members/{$membership->id}", [
        'status' => 'suspended',
    ])->assertUnprocessable()->assertJsonPath('code', 'own_membership');
});

it('cannot reach a membership through another organization id', function () {
    $other = createMember($this->w->c2, MembershipType::Staff);
    $membership = OrganizationMembership::where('user_id', $other->id)->sole();

    $this->asToken($this->token)->patchJson("/api/organizations/{$this->w->c1->id}/members/{$membership->id}", [
        'status' => 'suspended',
    ])->assertNotFound();
});
