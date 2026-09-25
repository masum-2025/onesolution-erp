<?php

use App\Models\User;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\RuleTargets;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\OrganizationMembership;

/*
 * Phase 5: usage limits of a subscription (the whole tree), enforced on the
 * server with a clear upgrade message.
 */

function planLimit(string $plan, string $rule, ?int $value): void
{
    ruleService()->set(app(RuleTargets::class)->plan($plan), $rule, RuleMode::Set, $value, 'Test limit', trusted: true);
}

beforeEach(function () {
    $this->w = tenancyWorld();
    // G1 is on the default Starter plan.
    planLimit('starter', 'plans.max_users', 3);
    planLimit('business', 'plans.max_users', 50);

    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->members = "/api/organizations/{$this->w->c1->id}/members";
});

function addByEmail(object $test, string $type = 'staff', ?string $url = null)
{
    return $test->asToken($test->token)->postJson($url ?? $test->members, ['email' => User::factory()->create()->email, 'membership_type' => $type]);
}

it('refuses a user beyond the plan with an upgrade hint', function () {
    addByEmail($this)->assertCreated();
    addByEmail($this, url: "/api/organizations/{$this->w->b1->id}/members")->assertCreated();

    // Owner + 2 staff across the tree = 3 of 3.
    addByEmail($this)->assertUnprocessable()
        ->assertJsonPath('code', 'users_limit_reached')
        ->assertJsonPath('max', 3)
        ->assertJsonPath('used', 3)
        ->assertJsonPath('upgrade.0.key', 'business')
        ->assertJsonPath('message', 'Your Starter plan includes 3 staff users, and all of them are in use. The Business plan includes 50. Or suspend someone who no longer needs access.');
});

it('does not count portal users, and counts a person once', function () {
    addByEmail($this)->assertCreated();
    addByEmail($this, 'portal')->assertCreated();
    addByEmail($this, 'portal')->assertCreated();

    // The same person in another unit of the subscription takes no new seat.
    $existing = OrganizationMembership::where('organization_id', $this->w->c1->id)->where('membership_type', 'staff')->first()->user;
    $this->asToken($this->token)->postJson("/api/organizations/{$this->w->b1->id}/members", ['email' => $existing->email, 'membership_type' => 'staff'])
        ->assertCreated();

    addByEmail($this)->assertCreated();
    addByEmail($this)->assertUnprocessable();
});

it('frees a seat on suspension and needs one to reactivate', function () {
    $response = addByEmail($this)->assertCreated();
    addByEmail($this)->assertCreated();
    $first = $response->json('data.id');

    $this->asToken($this->token)->patchJson("{$this->members}/{$first}", ['status' => 'suspended'])->assertOk();
    addByEmail($this)->assertCreated();

    $this->asToken($this->token)->patchJson("{$this->members}/{$first}", ['status' => 'active'])
        ->assertUnprocessable()->assertJsonPath('code', 'users_limit_reached');
});

it('counts the whole subscription, not one company', function () {
    addByEmail($this)->assertCreated();
    createMember($this->w->c2, MembershipType::Staff);

    addByEmail($this)->assertUnprocessable();

    // Another subscription (G2) has its own seats.
    $g2Owner = createMember($this->w->c3);
    $this->asToken(orgToken($g2Owner, $this->w->c3))->postJson("/api/organizations/{$this->w->c3->id}/members", [
        'email' => User::factory()->create()->email, 'membership_type' => 'staff',
    ])->assertCreated();
});

it('uses a partner limit only where the plan sets none', function () {
    // Plans sit below partners in the rule hierarchy, so a plan value wins over a
    // partner default. Per-client deals arrive with the partner layer (Phase 5B).
    ruleService()->set(app(RuleTargets::class)->partner($this->w->partnerA), 'plans.max_users', RuleMode::Set, 5, 'Partner A default', trusted: true);
    addByEmail($this)->assertCreated();
    addByEmail($this)->assertCreated();
    addByEmail($this)->assertUnprocessable()->assertJsonPath('max', 3);

    // Enterprise has no plan value: the partner default applies.
    setPlan($this->w->g1, 'enterprise');
    addByEmail($this)->assertCreated();
    addByEmail($this)->assertCreated();
    addByEmail($this)->assertUnprocessable()->assertJsonPath('max', 5);
});

it('refuses a branch beyond the plan', function () {
    planLimit('starter', 'plans.max_branches', 2);

    $this->asToken($this->token)->postJson('/api/organizations', [
        'parent_id' => $this->w->c1->id, 'type' => 'branch', 'name' => ['en' => 'Third'],
    ])->assertUnprocessable()->assertJsonPath('code', 'branches_limit_reached')->assertJsonPath('used', 2);

    // Departments are not limited.
    $this->asToken($this->token)->postJson('/api/organizations', [
        'parent_id' => $this->w->b1->id, 'type' => 'department', 'name' => ['en' => 'Science'],
    ])->assertCreated();
});

it('shows the plan and usage to members, not to portal users', function () {
    addByEmail($this, 'portal')->assertCreated();
    planLimit('starter', 'plans.max_branches', 5);

    $usage = $this->asToken($this->token)->getJson("/api/organizations/{$this->w->b1->id}/usage")->assertOk()->json('data');

    expect($usage['plan'])->toBe(['key' => 'starter', 'name' => 'Starter'])
        ->and($usage['subscription']['id'])->toBe($this->w->g1->id)
        ->and(collect($usage['limits'])->keyBy('limit')['users'])->toMatchArray(['max' => 3, 'used' => 1])
        ->and(collect($usage['limits'])->keyBy('limit')['branches'])->toMatchArray(['max' => 5, 'used' => 2])
        ->and(collect($usage['limits'])->keyBy('limit')['storage_mb']['max'])->toBeNull()
        ->and(collect($usage['modules_not_included'])->pluck('key'))->toContain('custom_reports');

    $portal = createMember($this->w->c1, MembershipType::Portal);
    $this->asToken(orgToken($portal, $this->w->c1))->getJson("/api/organizations/{$this->w->c1->id}/usage")->assertForbidden();
    $this->asToken($this->token)->getJson("/api/organizations/{$this->w->c4->id}/usage")->assertNotFound();
});

it('answers limits in Bangla', function () {
    addByEmail($this)->assertCreated();
    addByEmail($this)->assertCreated();

    $this->asToken($this->token)->withHeader('X-Locale', 'bn')->postJson($this->members, ['email' => User::factory()->create()->email, 'membership_type' => 'staff'])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'স্টার্টার প্ল্যানে'));
});
