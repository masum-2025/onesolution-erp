<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Modules\Events\ModuleDisabled;
use App\Platform\Modules\Models\OrganizationModule;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Support\Facades\Event;

/*
 * Phase 5 acceptance: downgrading a plan turns off modules it no longer
 * includes, everywhere in the subscription, and keeps their data.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    setPlan($this->w->g1, 'business');
    toggles()->enable($this->w->c1, 'custom_reports', 'Monthly board reports');

    $this->partnerToken = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA);
    $this->planUrl = "/api/partner/organizations/{$this->w->g1->id}/plan";
});

it('turns off modules the new plan leaves out and keeps their data', function () {
    Event::fake([ModuleDisabled::class]);

    $this->asToken($this->partnerToken)->putJson($this->planUrl, ['plan' => 'starter', 'reason' => 'Client asked to downgrade'])
        ->assertOk()
        ->assertJsonPath('data.plan.key', 'starter')
        ->assertJsonPath('data.modules_off.0.key', 'custom_reports')
        ->assertJsonPath('message', 'The plan is now Starter.');

    expect(resolvedModule('custom_reports', $this->w->c1)->enabled)->toBeFalse()
        ->and(resolvedModule('custom_reports', $this->w->c1)->reason->value)->toBe('not_in_plan')
        ->and(resolvedModule('custom_reports', $this->w->b1)->enabled)->toBeFalse()
        // The company's own setting (data) is still there.
        ->and(OrganizationModule::where('organization_id', $this->w->c1->id)->where('module_key', 'custom_reports')->value('state')->value)->toBe('enabled');

    // One event, at the highest unit where the module stopped.
    Event::assertDispatchedTimes(ModuleDisabled::class, 1);
    Event::assertDispatched(ModuleDisabled::class, fn ($event) => $event->moduleKey === 'custom_reports' && $event->organization->is($this->w->c1));

    $audit = AuditLog::where('action', 'organization.plan_changed')->sole();
    expect($audit->old_values)->toMatchArray(['plan' => 'business', 'partner_plan' => null, 'currency' => 'BDT', 'period' => 'monthly'])
        ->and($audit->new_values['plan'])->toBe('starter')
        ->and($audit->partner_id)->toBe($this->w->partnerA->id)
        ->and($audit->reason)->toBe('Client asked to downgrade');

    // Upgrading again brings it back as it was.
    $this->asToken($this->partnerToken)->putJson($this->planUrl, ['plan' => 'business', 'reason' => 'Upgraded again'])->assertOk();
    expect(resolvedModule('custom_reports', $this->w->c1)->enabled)->toBeTrue();
});

it('makes every unit follow the subscription plan', function () {
    setPlan($this->w->c2, 'enterprise');

    $this->asToken($this->partnerToken)->putJson($this->planUrl, ['plan' => 'starter', 'reason' => 'One plan for the group'])->assertOk();

    expect($this->w->c2->fresh()->plan_key)->toBeNull()
        ->and($this->w->g1->fresh()->plan_key)->toBe('starter');
});

it('previews a change without making it', function () {
    ruleService()->set(app(App\Platform\Rules\RuleTargets::class)->plan('starter'), 'plans.max_branches', App\Platform\Rules\Enums\RuleMode::Set, 1, 'Test', trusted: true);

    $preview = $this->asToken($this->partnerToken)->getJson("/api/partner/organizations/{$this->w->g1->id}/plan-preview?plan=starter")
        ->assertOk()->json('data');

    expect(collect($preview['modules_off'])->pluck('key')->all())->toBe(['custom_reports'])
        ->and($preview['over_limits'])->toBe([['limit' => 'branches', 'max' => 1, 'used' => 2]])
        ->and($this->w->g1->fresh()->plan_key)->toBe('business')
        ->and(resolvedModule('custom_reports', $this->w->c1)->enabled)->toBeTrue()
        ->and(AuditLog::where('action', 'organization.plan_changed')->exists())->toBeFalse();
});

it('allows a downgrade over a limit but refuses new branches until there is room', function () {
    ruleService()->set(app(App\Platform\Rules\RuleTargets::class)->plan('starter'), 'plans.max_branches', App\Platform\Rules\Enums\RuleMode::Set, 1, 'Test', trusted: true);

    $this->asToken($this->partnerToken)->putJson($this->planUrl, ['plan' => 'starter', 'reason' => 'Budget cut'])
        ->assertOk()
        ->assertJsonPath('data.over_limits.0.limit', 'branches');

    // Nothing was removed.
    expect(App\Platform\Tenancy\Models\Organization::where('root_id', $this->w->g1->id)->where('type', 'branch')->count())->toBe(2);

    $owner = createMember($this->w->c1);
    $this->asToken(orgToken($owner, $this->w->c1))->postJson('/api/organizations', [
        'parent_id' => $this->w->c1->id, 'type' => 'branch', 'name' => ['en' => 'Third'],
    ])->assertUnprocessable()->assertJsonPath('code', 'branches_limit_reached');
});

it('lets only partner owners and billing staff change plans', function (PartnerUserRole $role, int $status) {
    $token = partnerToken(createPartnerStaff($this->w->partnerA, $role), $this->w->partnerA);

    $this->asToken($token)->putJson($this->planUrl, ['plan' => 'starter', 'reason' => 'Downgrade request'])->assertStatus($status);
})->with([
    'billing' => [PartnerUserRole::Billing, 200],
    'support' => [PartnerUserRole::Support, 403],
    'sales' => [PartnerUserRole::Sales, 403],
]);

it('never reaches another partner\'s clients', function () {
    $this->asToken($this->partnerToken)->putJson("/api/partner/organizations/{$this->w->g3->id}/plan", ['plan' => 'starter', 'reason' => 'Cross-partner attempt'])
        ->assertNotFound();
    $this->asToken($this->partnerToken)->getJson("/api/partner/organizations/{$this->w->g3->id}/plan-preview?plan=starter")->assertNotFound();

    expect($this->w->g3->fresh()->plan_key)->toBeNull();
});

it('changes plans only at the top of a subscription', function () {
    $this->asToken($this->partnerToken)->putJson("/api/partner/organizations/{$this->w->c1->id}/plan", ['plan' => 'enterprise', 'reason' => 'Only this company'])
        ->assertUnprocessable()->assertJsonPath('code', 'not_top_level');
});

it('validates plan changes', function (array $body, string $field) {
    $this->asToken($this->partnerToken)->putJson($this->planUrl, $body)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'unknown plan' => [['plan' => 'platinum', 'reason' => 'Upgrade'], 'plan'],
    'no reason' => [['plan' => 'starter'], 'reason'],
    'unknown field' => [['plan' => 'starter', 'reason' => 'Upgrade', 'price' => 0], 'price'],
]);

it('refuses a change to the same plan', function () {
    $this->asToken($this->partnerToken)->putJson($this->planUrl, ['plan' => 'business', 'reason' => 'Nothing to do'])
        ->assertUnprocessable()->assertJsonPath('code', 'same_plan');
});

it('keeps plan changes out of the client area', function () {
    $owner = createMember($this->w->g1);

    $this->asToken(orgToken($owner, $this->w->g1))->putJson($this->planUrl, ['plan' => 'enterprise', 'reason' => 'Self upgrade'])
        ->assertForbidden();
});
