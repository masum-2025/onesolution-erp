<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\RuleTargets;
use App\Platform\SupportAccess\Models\SupportGrant;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\PartnerUserRole;

/*
 * Phase 5B-2 acceptance: partner staff read client data only with approved,
 * time-limited, read-only support access; every step shows in the client's
 * own audit log.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->staff = createPartnerStaff($this->w->partnerA, PartnerUserRole::Support);
    $this->staffToken = partnerToken($this->staff, $this->w->partnerA);
    $this->owner = createMember($this->w->c1);
    $this->ownerToken = orgToken($this->owner, $this->w->c1);
});

function askSupport(object $test, array $overrides = [])
{
    return $test->asToken($test->staffToken)->postJson('http://localhost/api/partner/support-grants', [
        'organization_id' => $test->w->c1->id,
        'reason' => 'Payslips show the wrong overtime; need to check the settings.',
        'severity' => 'normal',
        'minutes' => 60,
        ...$overrides,
    ]);
}

/**
 * The staff member's browser: partner console, then the client via the grant.
 */
function enterAsSupport(object $test, string $grantId)
{
    // A browser: no API token header or cached token user left over from earlier calls in the test.
    $test->withoutToken();
    app("auth")->forgetGuards();
    spaSession($test, $test->staff, partner: $test->w->partnerA);

    return $test->postJson('/session/context', ['support_grant_id' => $grantId]);
}

/**
 * Back to an API client (the client's owner): no browser Origin, so the token is used.
 */
function asClientApi(object $test)
{
    // Tests share one session store: leave the staff member's browser session first.
    $test->withoutHeader('Origin');
    $test->flushSession();

    return $test->asToken($test->ownerToken);
}

it('refuses client data to partner staff without approved access', function () {
    // A partner token has no client context at all.
    $this->asToken($this->staffToken)->getJson('/api/organizations')->assertForbidden();

    // A pending request does not open anything.
    $grantId = askSupport($this)->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
    enterAsSupport($this, $grantId)->assertForbidden()->assertJsonPath('code', 'no_support_access');
    $this->getJson('/api/organizations')->assertForbidden();
});

it('lets approved support read, never change, and logs every page in the client\'s audit log', function () {
    $grantId = askSupport($this)->json('data.id');

    $this->asToken($this->ownerToken)->postJson("http://localhost/api/organizations/{$this->w->c1->id}/support-grants/{$grantId}/approve", ['reason' => 'Go ahead'])
        ->assertOk()->assertJsonPath('data.status', 'approved');

    enterAsSupport($this, $grantId)->assertOk();

    $this->getJson('/api/me')->assertJsonPath('data.context.mode', 'read_only')
        ->assertJsonPath('data.context.mode_reason', 'support')
        ->assertJsonPath('data.permissions', []);
    $this->getJson("/api/organizations/{$this->w->c1->id}/rules")->assertOk();
    $this->getJson("/api/organizations/{$this->w->b1->id}")->assertOk();

    // Read-only, whatever the route.
    $this->patchJson("/api/organizations/{$this->w->c1->id}", ['name' => ['en' => 'Changed by support']])
        ->assertForbidden()->assertJsonPath('code', 'read_only_support');
    $this->postJson("/api/organizations/{$this->w->c1->id}/exports")->assertForbidden();
    // Other clients stay closed.
    $this->getJson("/api/organizations/{$this->w->c2->id}")->assertNotFound();

    $log = asClientApi($this)->getJson("http://localhost/api/organizations/{$this->w->c1->id}/audit-log?filter=support")->assertOk()->json('data');
    $actions = collect($log)->pluck('action');

    expect($actions)->toContain('support.requested', 'support.approved', 'support.session_started', 'support.accessed')
        ->and(collect($log)->firstWhere('action', 'support.accessed')['actor']['name'])->toBe($this->staff->name)
        ->and(collect($log)->firstWhere('action', 'support.accessed')['label'])->toBe('Support staff opened a page');

    // The refused change is in the log too, marked as blocked.
    $tried = collect($log)->first(fn ($entry) => $entry['action'] === 'support.accessed' && $entry['new_values']['method'] === 'PATCH');
    expect($tried['new_values']['blocked'])->toBeTrue();
});

it('ends access by itself when the time is up and shows it in the client\'s log', function () {
    $grantId = askSupport($this, ['minutes' => 30])->json('data.id');
    $this->asToken($this->ownerToken)->postJson("http://localhost/api/organizations/{$this->w->c1->id}/support-grants/{$grantId}/approve")->assertOk();
    enterAsSupport($this, $grantId)->assertOk();
    $this->getJson('/api/organizations')->assertOk();

    $this->travel(31)->minutes();

    // Refused at once, even before the scheduler runs.
    $this->getJson('/api/organizations')->assertForbidden();

    $this->artisan('support:expire')->assertSuccessful();

    expect(SupportGrant::find($grantId)->status->value)->toBe('expired')
        ->and(AuditLog::where('action', 'support.expired')->where('organization_id', $this->w->c1->id)->exists())->toBeTrue();
});

it('lets the client end access early, and the staff member is out at once', function () {
    $grantId = askSupport($this)->json('data.id');
    $this->asToken($this->ownerToken)->postJson("http://localhost/api/organizations/{$this->w->c1->id}/support-grants/{$grantId}/approve")->assertOk();
    enterAsSupport($this, $grantId)->assertOk();
    $this->getJson('/api/organizations')->assertOk();

    // The client ends it while the staff member is inside (API flow covered below).
    app(App\Platform\SupportAccess\Services\SupportAccessService::class)->revoke(SupportGrant::find($grantId), $this->owner, 'Problem solved, thanks');

    $this->getJson('/api/organizations')->assertForbidden()->assertJsonPath('code', 'support_ended');
});

it('ends access from the client\'s support screen', function () {
    $grantId = askSupport($this)->json('data.id');
    $url = "http://localhost/api/organizations/{$this->w->c1->id}/support-grants/{$grantId}";

    $this->asToken($this->ownerToken)->postJson("{$url}/approve")->assertOk();
    $this->asToken($this->ownerToken)->postJson("{$url}/revoke", ['reason' => 'Problem solved, thanks'])
        ->assertOk()->assertJsonPath('data.status', 'revoked');
    $this->asToken($this->ownerToken)->postJson("{$url}/revoke", ['reason' => 'Problem solved, thanks'])
        ->assertUnprocessable()->assertJsonPath('code', 'not_active');

    $list = $this->asToken($this->ownerToken)->getJson("http://localhost/api/organizations/{$this->w->c1->id}/support-grants")->assertOk();
    expect($list->json('data.0.status'))->toBe('revoked')->and($list->json('max_minutes'))->toBe(120);
});

it('approves by the client\'s rule for the severities it chose', function () {
    // A sensitive rule: a second person approves it.
    $row = ruleService()->set(app(RuleTargets::class)->organization($this->w->c1), 'support.auto_approve_severities', RuleMode::Set, ['critical'], 'Outages cannot wait', $this->owner);
    ruleService()->approve($row, createMember($this->w->g1));

    askSupport($this, ['severity' => 'normal'])->assertJsonPath('data.status', 'pending');

    $other = createPartnerStaff($this->w->partnerA, PartnerUserRole::Support);
    $this->staffToken = partnerToken($other, $this->w->partnerA);
    askSupport($this, ['severity' => 'critical'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.auto_approved', true)
        ->assertJsonPath('message', 'The client lets this kind of request in at once. You can enter now.');
});

it('keeps requests inside the client\'s time limit and one open request per person', function () {
    askSupport($this, ['minutes' => 300])->assertUnprocessable()->assertJsonPath('code', 'too_long')->assertJsonPath('max', 120);
    askSupport($this)->assertCreated();
    askSupport($this)->assertUnprocessable()->assertJsonPath('code', 'already_open');
});

it('never lets anyone else use a grant', function () {
    $grantId = askSupport($this)->json('data.id');
    $this->asToken($this->ownerToken)->postJson("http://localhost/api/organizations/{$this->w->c1->id}/support-grants/{$grantId}/approve")->assertOk();

    $colleague = createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner);
    spaSession($this, $colleague, partner: $this->w->partnerA);
    $this->postJson('/session/context', ['support_grant_id' => $grantId])->assertForbidden()->assertJsonPath('code', 'no_support_access');

    // Nor through an API token.
    $this->asToken($this->staffToken)->postJson('http://localhost/api/auth/context', ['support_grant_id' => $grantId])
        ->assertUnprocessable()->assertJsonValidationErrors('support_grant_id');
});

it('keeps support requests inside the partner', function () {
    askSupport($this, ['organization_id' => $this->w->c4->id])->assertNotFound();

    $billing = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Billing), $this->w->partnerA);
    $this->asToken($billing)->postJson('http://localhost/api/partner/support-grants', [
        'organization_id' => $this->w->c1->id, 'reason' => 'Billing wants to look around.', 'severity' => 'low', 'minutes' => 30,
    ])->assertForbidden()->assertJsonPath('code', 'role_not_allowed');

    $grantId = askSupport($this)->json('data.id');
    $bOwner = createMember($this->w->c4);
    $this->asToken(orgToken($bOwner, $this->w->c4))->postJson("http://localhost/api/organizations/{$this->w->c4->id}/support-grants/{$grantId}/approve")->assertNotFound();
});

it('needs the approval permission to answer requests', function () {
    $grantId = askSupport($this)->json('data.id');
    $staffMember = createMember($this->w->c1, MembershipType::Staff);

    $this->asToken(orgToken($staffMember, $this->w->c1))->postJson("http://localhost/api/organizations/{$this->w->c1->id}/support-grants/{$grantId}/approve")->assertForbidden();
    $this->asToken(orgToken($staffMember, $this->w->c1))->getJson("http://localhost/api/organizations/{$this->w->c1->id}/audit-log")->assertForbidden();

    $approver = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['support.approve'], 'Support approver'));
    $this->asToken(orgToken($approver, $this->w->c1))->postJson("http://localhost/api/organizations/{$this->w->c1->id}/support-grants/{$grantId}/reject", ['reason' => 'Not now, month end'])
        ->assertOk()->assertJsonPath('data.status', 'rejected');
});

it('lists requests for the partner: owners see all, support staff their own', function () {
    askSupport($this)->assertCreated();
    $other = createPartnerStaff($this->w->partnerA, PartnerUserRole::Support);
    $this->staffToken = partnerToken($other, $this->w->partnerA);
    askSupport($this)->assertCreated();

    $owner = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA);
    expect($this->asToken($owner)->getJson('http://localhost/api/partner/support-grants')->json('data'))->toHaveCount(2)
        ->and($this->asToken($this->staffToken)->getJson('http://localhost/api/partner/support-grants')->json('data'))->toHaveCount(1);

    $bOwner = partnerToken(createPartnerStaff($this->w->partnerB, PartnerUserRole::Owner), $this->w->partnerB);
    expect($this->asToken($bOwner)->getJson('http://localhost/api/partner/support-grants')->json('data'))->toHaveCount(0);
});
