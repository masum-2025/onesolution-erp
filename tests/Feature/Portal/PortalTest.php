<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Identity\Jobs\SendOtp;
use App\Platform\Portal\Jobs\SendPortalInvitation;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Portal\PortalAccess;
use App\Platform\Portal\PortalSubjects;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Fixtures\FixtureStudent;
use Tests\Fixtures\FixtureStudentProvider;

/*
 * B2B2C portals (Phase 5C-4). A school invites a parent to see their child;
 * the parent sees that child and nothing else: not other children, not the
 * school's organization, members, rules or billing.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    app(PortalSubjects::class)->register('client_portal', FixtureStudentProvider::class);
    toggles()->enable($this->w->g1, 'client_portal', 'Test setup');
    toggles()->enable($this->w->g3, 'client_portal', 'Test setup');

    $this->admin = createMember($this->w->c1, MembershipType::Owner);
    $this->rahim = FixtureStudent::query()->create(['organization_id' => $this->w->c1->id, 'name' => 'Rahim', 'class_name' => 'Class 5', 'date_of_birth' => '2016-03-01']);
    $this->karim = FixtureStudent::query()->create(['organization_id' => $this->w->c1->id, 'name' => 'Karim', 'class_name' => 'Class 6']);
    $this->withHeader('Origin', config('app.url'));
    RateLimiter::clear('portal-join:127.0.0.1');
});

function invite(object $test, array $overrides = []): array
{
    Bus::fake([SendPortalInvitation::class]);

    return $test->asToken(orgToken($test->admin, $test->w->c1))
        ->postJson("/api/organizations/{$test->w->c1->id}/portal/invitations", [
            'subject_type' => 'school.student',
            'subject_id' => $test->rahim->id,
            'relation' => 'guardian',
            'name' => 'Rahim\'s mother',
            'channel' => 'mail',
            'email' => 'mother@example.com',
            ...$overrides,
        ])->assertCreated()->json('data');
}

/** The parent's account, with the invited email verified. */
function parentAccount(string $email = 'mother@example.com'): User
{
    return User::factory()->create(['email' => $email, 'email_verified_at' => now()]);
}

function joinAs(object $test, User $user, string $key): Illuminate\Testing\TestResponse
{
    $test->actingAs($user, 'web');

    return $test->postJson('/session/portal/join', ['key' => $key]);
}

function approveAll(object $test): void
{
    foreach (PortalLink::query()->where('status', PortalLink::PENDING)->get() as $link) {
        $test->asToken(orgToken($test->admin, $test->w->c1))
            ->postJson("/api/organizations/{$test->w->c1->id}/portal/links/{$link->id}/approve")->assertOk();
    }
}

it('invites a parent: link and code once, a message to the invited address, audited', function () {
    $invitation = invite($this);

    expect($invitation['code'])->toMatch('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/')
        ->and($invitation['link'])->toContain('/portal/join/');
    Bus::assertDispatched(SendPortalInvitation::class, fn (SendPortalInvitation $job) => $job->to === 'mother@example.com' && $job->code === $invitation['code']);
    expect(AuditLog::query()->where('action', 'portal.invited')->exists())->toBeTrue();

    // Anyone can see what the invitation is for, without learning the address.
    $this->getJson('/session/portal/invitations/'.str_replace('-', '', $invitation['code']))
        ->assertOk()
        ->assertJsonPath('data.organization', 'C1')
        ->assertJsonPath('data.kind', 'Student')
        ->assertJsonPath('data.to', fn (string $to) => ! str_contains($to, 'mother@'));
});

it('lets the parent see their own child only, after the school approves', function () {
    $invitation = invite($this);
    $parent = parentAccount();

    joinAs($this, $parent, $invitation['code'])->assertOk()->assertJsonPath('data.status', 'pending');

    // Waiting: the portal shows the link, not the child.
    $token = orgToken($parent, $this->w->c1);
    $this->asToken($token)->getJson('/api/portal')->assertOk()
        ->assertJsonPath('data.records.0.status', 'pending')
        ->assertJsonPath('data.records.0.name', null);

    approveAll($this);
    $link = PortalLink::query()->sole();

    $this->asToken($token)->getJson('/api/portal')->assertOk()->assertJsonPath('data.records.0.name', 'Rahim');
    $this->asToken($token)->getJson("/api/portal/records/{$link->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Rahim')
        ->assertJsonPath('data.fields.1.value', 'Class 5');

    // Modules restrict their own queries the same way.
    actInOrganization($parent, $this->w->c1);
    $visible = app(PortalAccess::class)->restrict(FixtureStudent::query(), 'school.student')->pluck('name')->all();
    expect($visible)->toBe(['Rahim']);
});

it('never shows another child, whatever id is tried', function () {
    $parent = parentAccount();
    joinAs($this, $parent, invite($this)['code'])->assertOk();
    approveAll($this);
    $token = orgToken($parent, $this->w->c1);

    // Another family's link to Karim.
    $other = parentAccount('father@example.com');
    joinAs($this, $other, invite($this, ['subject_id' => $this->karim->id, 'email' => 'father@example.com'])['code'])->assertOk();
    approveAll($this);
    $karimsLink = PortalLink::query()->where('subject_id', $this->karim->id)->sole();

    $this->asToken($token)->getJson("/api/portal/records/{$karimsLink->id}")->assertNotFound();
    $this->asToken($token)->getJson('/api/portal/records/'.$this->karim->id)->assertNotFound();
    $this->asToken($token)->getJson('/api/portal')->assertJsonCount(1, 'data.records');
});

it('keeps portal members out of everything else in the organization', function () {
    $parent = parentAccount();
    joinAs($this, $parent, invite($this)['code'])->assertOk();
    approveAll($this);
    $token = orgToken($parent, $this->w->c1);

    foreach ([
        '/api/organizations',
        "/api/organizations/{$this->w->c1->id}",
        "/api/organizations/{$this->w->c1->id}/members",
        "/api/organizations/{$this->w->c1->id}/rules",
        "/api/organizations/{$this->w->c1->id}/billing",
        "/api/organizations/{$this->w->c1->id}/portal",
        '/api/menu',
    ] as $path) {
        $this->asToken($token)->getJson($path)->assertForbidden()->assertJsonPath('code', 'portal_only');
    }
});

it('stops showing a record the moment access is revoked', function () {
    $parent = parentAccount();
    joinAs($this, $parent, invite($this)['code'])->assertOk();
    approveAll($this);
    $link = PortalLink::query()->sole();
    $token = orgToken($parent, $this->w->c1);
    $this->asToken($token)->getJson("/api/portal/records/{$link->id}")->assertOk();

    $this->asToken(orgToken($this->admin, $this->w->c1))
        ->postJson("/api/organizations/{$this->w->c1->id}/portal/links/{$link->id}/revoke", ['reason' => 'Guardian changed'])->assertOk();

    $this->asToken($token)->getJson("/api/portal/records/{$link->id}")->assertNotFound();
    expect(AuditLog::query()->where('action', 'portal.link_revoked')->where('reason', 'Guardian changed')->exists())->toBeTrue();
});

it('hides the fields the school chooses', function () {
    $parent = parentAccount();
    joinAs($this, $parent, invite($this)['code'])->assertOk();
    approveAll($this);
    orgRule($this->w->c1, 'client_portal.hidden_fields', ['school.student.date_of_birth']);

    $fields = $this->asToken(orgToken($parent, $this->w->c1))->getJson('/api/portal/records/'.PortalLink::query()->sole()->id)->json('data.fields');

    expect(array_column($fields, 'key'))->toBe(['name', 'class_name']);
});

it('links at once when the school allows verified joins', function () {
    orgRule($this->w->c1, 'client_portal.link_approval', 'auto_verified');

    joinAs($this, parentAccount(), invite($this)['code'])->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('message', 'You are in. You can see the record now.');
});

it('only lets the invited address use an invitation', function () {
    $invitation = invite($this);

    // Someone else, or the right email not verified.
    joinAs($this, parentAccount('stranger@example.com'), $invitation['code'])->assertStatus(422)->assertJsonPath('code', 'contact_mismatch_mail');
    $unverified = User::factory()->unverified()->create(['email' => 'mother@example.com']);
    joinAs($this, $unverified, $invitation['code'])->assertStatus(422);

    expect(PortalLink::query()->count())->toBe(0);
});

it('creates an account with a code sent to the invited phone, and no personal workspace', function () {
    $invitation = invite($this, ['channel' => 'sms', 'email' => null, 'phone' => '01712345678', 'country_code' => 'BD']);
    Bus::fake([SendOtp::class]);

    $challenge = $this->postJson('/session/portal/signup', [
        'key' => $invitation['code'], 'name' => 'Rahima', 'password' => 'Secret-pass-2026', 'password_confirmation' => 'Secret-pass-2026',
    ])->assertStatus(202)->json('data.challenge_id');

    $otp = Bus::dispatched(SendOtp::class)->last();
    expect($otp->to)->toBe('+8801712345678');

    $this->postJson('/session/portal/signup/verify', ['challenge_id' => $challenge, 'code' => $otp->code])
        ->assertOk()
        ->assertJsonPath('data.organization_id', $this->w->c1->id);

    $user = User::query()->where('phone', '+8801712345678')->sole();
    expect($user->phone_verified_at)->not->toBeNull()
        ->and(OrganizationMembership::query()->where('user_id', $user->id)->pluck('membership_type')->map->value->all())->toBe(['portal'])
        ->and(PortalLink::query()->where('user_id', $user->id)->sole()->status)->toBe('pending');
});

it('uses an invitation once, and never after it is cancelled or expired', function () {
    $invitation = invite($this);
    joinAs($this, parentAccount(), $invitation['code'])->assertOk();
    joinAs($this, parentAccount('someone@example.com'), $invitation['code'])->assertStatus(410);

    $second = invite($this, ['email' => 'aunt@example.com']);
    $this->asToken(orgToken($this->admin, $this->w->c1))
        ->deleteJson("/api/organizations/{$this->w->c1->id}/portal/invitations/{$second['id']}")->assertOk();
    joinAs($this, parentAccount('aunt@example.com'), $second['code'])->assertStatus(410);

    $third = invite($this, ['email' => 'uncle@example.com']);
    $this->travel(15)->days();
    joinAs($this, parentAccount('uncle@example.com'), $third['code'])->assertStatus(410);
});

it('makes guessing codes useless', function () {
    foreach (range(1, 10) as $ignored) {
        $this->getJson('/session/portal/invitations/ABCDE23456')->assertNotFound();
    }

    $this->getJson('/session/portal/invitations/ABCDE23456')->assertStatus(429);
});

it('turns off with the module: invitations and records answer 403', function () {
    $parent = parentAccount();
    joinAs($this, $parent, invite($this)['code'])->assertOk();
    approveAll($this);

    toggles()->disable($this->w->g1, 'client_portal', 'Test setup', confirm: true);

    $this->asToken(orgToken($parent, $this->w->c1))->getJson('/api/portal')->assertForbidden()->assertJsonPath('code', 'module_disabled');
    $this->asToken(orgToken($this->admin, $this->w->c1))->getJson("/api/organizations/{$this->w->c1->id}/portal")->assertForbidden();
    // Nothing was deleted.
    expect(PortalLink::query()->count())->toBe(1);
});

it('lets only people with the portal permission manage it', function () {
    $teacher = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['client_portal.view']));
    $token = orgToken($teacher, $this->w->c1);

    $this->asToken($token)->getJson("/api/organizations/{$this->w->c1->id}/portal")->assertOk()->assertJsonPath('data.can_manage', false);
    $this->asToken($token)->postJson("/api/organizations/{$this->w->c1->id}/portal/invitations", [
        'subject_type' => 'school.student', 'subject_id' => $this->rahim->id, 'relation' => 'guardian', 'name' => 'Parent X', 'channel' => 'mail', 'email' => 'x@example.com',
    ])->assertForbidden();
});

it('keeps clients apart: no inviting to, or joining with, another client\'s records', function () {
    $otherAdmin = createMember($this->w->c2, MembershipType::Owner);

    // C2 cannot invite to a C1 student.
    $this->asToken(orgToken($otherAdmin, $this->w->c2))->postJson("/api/organizations/{$this->w->c2->id}/portal/invitations", [
        'subject_type' => 'school.student', 'subject_id' => $this->rahim->id, 'relation' => 'guardian', 'name' => 'Parent X', 'channel' => 'mail', 'email' => 'x@example.com',
    ])->assertNotFound()->assertJsonPath('code', 'record_not_found');

    // A C1 parent is nobody in C2, and C2 staff never see C1's links.
    $parent = parentAccount();
    joinAs($this, $parent, invite($this)['code'])->assertOk();
    $this->asToken(orgToken($otherAdmin, $this->w->c2))->getJson("/api/organizations/{$this->w->c2->id}/portal")->assertOk()->assertJsonCount(0, 'data.links');
    $link = PortalLink::query()->sole();
    $this->asToken(orgToken($otherAdmin, $this->w->c2))->postJson("/api/organizations/{$this->w->c2->id}/portal/links/{$link->id}/approve")->assertNotFound();

    // Another partner's client cannot even address C1.
    $foreign = createMember($this->w->c4, MembershipType::Owner);
    $this->asToken(orgToken($foreign, $this->w->c4))->getJson("/api/organizations/{$this->w->c1->id}/portal")->assertNotFound();
});

it('limits how many records one person is linked to', function () {
    orgRule($this->w->c1, 'client_portal.max_links_per_person', 1);
    $parent = parentAccount();
    joinAs($this, $parent, invite($this)['code'])->assertOk();

    joinAs($this, $parent, invite($this, ['subject_id' => $this->karim->id])['code'])
        ->assertStatus(422)->assertJsonPath('code', 'too_many_links');
});

it('does not give staff a portal link', function () {
    $invitation = invite($this, ['email' => $this->admin->email]);

    joinAs($this, $this->admin, $invitation['code'])->assertStatus(409)->assertJsonPath('code', 'already_staff');
});
