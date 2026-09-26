<?php

use App\Models\User;
use App\Platform\Access\Models\Role;
use App\Platform\Packaging\Services\UsageLimiter;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\Event;

/*
 * Phase 5B: the partner console manages client accounts and sets modules
 * and rules for all clients, inside platform governance, never reaching
 * another partner's clients.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->ownerToken = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA);
    $this->clientOwner = User::factory()->create(['email' => 'head@school.test']);
});

function newClient(object $test, ?string $token = null, array $overrides = [])
{
    return $test->asToken($token ?? $test->ownerToken)->postJson('http://localhost/api/partner/clients', [
        'name' => ['en' => 'Sunrise School'],
        'sector_key' => 'school',
        'plan' => 'business',
        'owner_email' => 'head@school.test',
        'country_code' => 'BD',
        ...$overrides,
    ]);
}

// ── Client accounts ──

it('creates a client with its company, owner and sector package', function () {
    $response = newClient($this)->assertCreated()->assertJsonPath('data.subscription_plan', 'business');

    $group = Organization::findOrFail($response->json('data.id'));
    $company = Organization::findOrFail($response->json('company.id'));

    expect($group->partner_id)->toBe($this->w->partnerA->id)
        ->and($group->isRoot())->toBeTrue()
        ->and($company->parent_id)->toBe($group->id)
        ->and($company->sector_key)->toBe('school')
        ->and(resolvedModule('payroll', $company)->enabled)->toBeTrue()
        ->and(Role::where('organization_id', $company->id)->where('template_key', 'principal')->exists())->toBeTrue()
        ->and($group->memberships()->where('user_id', $this->clientOwner->id)->value('membership_type')->value)->toBe('owner');
});

it('lets sales create clients but not support staff', function () {
    newClient($this, partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Sales), $this->w->partnerA))->assertCreated();
    newClient($this, partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Support), $this->w->partnerA), ['name' => ['en' => 'Other']])
        ->assertForbidden()->assertJsonPath('code', 'role_not_allowed');
});

it('validates new clients', function (array $overrides, string $field) {
    newClient($this, overrides: $overrides)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'unknown sector' => [['sector_key' => 'spaceport'], 'sector_key'],
    'unknown plan' => [['plan' => 'platinum'], 'plan'],
    'no account for the owner' => [['owner_email' => 'nobody@example.test'], 'owner_email'],
    'partner in the body' => [['partner_id' => 'x'], 'partner_id'],
]);

it('keeps partners within the platform\'s limits', function () {
    // G1 and G2 already exist for Partner A.
    partnerRule($this->w->partnerA, 'partners.max_clients', 2);
    newClient($this)->assertUnprocessable()->assertJsonPath('code', 'client_limit_reached');

    partnerRule($this->w->partnerA, 'partners.max_clients', 5);
    partnerRule($this->w->partnerA, 'partners.allowed_countries', ['BD']);
    newClient($this, overrides: ['country_code' => 'IN'])->assertUnprocessable()->assertJsonPath('code', 'country_not_allowed');
    newClient($this)->assertCreated();
});

it('suspends a client so nobody in it can sign in', function () {
    $member = createMember($this->w->c1);
    $memberToken = orgToken($member, $this->w->c1);

    $this->asToken($this->ownerToken)->patchJson("/api/partner/clients/{$this->w->g1->id}/status", ['status' => 'suspended', 'reason' => 'Unpaid for three months'])
        ->assertOk()->assertJsonPath('data.status', 'suspended');

    // Even a token issued before the suspension stops working.
    $this->asToken($memberToken)->getJson('/api/organizations')->assertForbidden()->assertJsonPath('code', 'organization_inactive');

    $this->asToken($this->ownerToken)->patchJson("/api/partner/clients/{$this->w->g1->id}/status", ['status' => 'active', 'reason' => 'Paid in full'])->assertOk();
    $this->asToken($memberToken)->getJson('/api/organizations')->assertOk();
});

it('gives one client a limit deal that the client cannot change', function () {
    ruleService()->set(app(App\Platform\Rules\RuleTargets::class)->plan('starter'), 'plans.max_users', RuleMode::Set, 3, 'Test', trusted: true);
    $billing = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Billing), $this->w->partnerA);

    $this->asToken($billing)->putJson("/api/partner/clients/{$this->w->g1->id}/limits", ['limits' => ['users' => 30], 'reason' => 'Signed a 30-seat deal'])
        ->assertOk()->assertJsonPath('data.deal.users', 30)->assertJsonPath('data.effective.users', 30);

    expect(app(UsageLimiter::class)->limits($this->w->c1)['users'])->toBe(30)
        // Another client of the same plan keeps the plan's limit.
        ->and(app(UsageLimiter::class)->limits($this->w->c3)['users'])->toBe(3);

    // The client's own owner cannot touch it.
    $owner = createMember($this->w->g1);
    $this->asToken(orgToken($owner, $this->w->g1))->putJson("/api/organizations/{$this->w->g1->id}/rules/plans.max_users", ['mode' => 'set', 'value' => 999, 'reason' => 'More seats please'])
        ->assertForbidden();

    // Back to the plan.
    $this->asToken($billing)->putJson("http://localhost/api/partner/clients/{$this->w->g1->id}/limits", ['limits' => ['users' => null], 'reason' => 'Deal ended'])
        ->assertOk()->assertJsonPath('data.deal.users', null)->assertJsonPath('data.effective.users', 3);
});

it('never reaches another partner\'s clients', function () {
    $this->asToken($this->ownerToken)->patchJson("/api/partner/clients/{$this->w->g3->id}/status", ['status' => 'suspended', 'reason' => 'Cross-partner attempt'])->assertNotFound();
    $this->asToken($this->ownerToken)->getJson("/api/partner/clients/{$this->w->g3->id}/limits")->assertNotFound();
    $this->asToken($this->ownerToken)->putJson("/api/partner/clients/{$this->w->g3->id}/limits", ['limits' => ['users' => 1], 'reason' => 'Cross-partner attempt'])->assertNotFound();
    // A unit inside a client is not a client account.
    $this->asToken($this->ownerToken)->getJson("/api/partner/clients/{$this->w->c1->id}/limits")->assertNotFound();

    expect($this->w->g3->fresh()->status->value)->toBe('active');
});

// ── Modules and rules for all clients ──

it('locks a module on for all clients so none can turn it off', function () {
    Event::fake([App\Platform\Modules\Events\ModuleEnabled::class]);

    $this->asToken($this->ownerToken)->putJson('/api/partner/modules/crm', ['state' => 'enabled', 'lock' => true, 'reason' => 'Every client gets CRM'])
        ->assertOk();

    expect(resolvedModule('crm', $this->w->c1)->enabled)->toBeTrue()
        ->and(resolvedModule('crm', $this->w->c3)->source)->toBe('partner')
        ->and(resolvedModule('crm', $this->w->c4)->enabled)->toBeFalse();

    $owner = createMember($this->w->c1);
    $this->asToken(orgToken($owner, $this->w->c1))->postJson("/api/organizations/{$this->w->c1->id}/modules/crm/disable", ['reason' => 'We do not need it'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'locked_by_parent')
        ->assertJsonPath('message', 'Customer Relations is locked by your service provider. Ask them to change it.');

    // One event per client tree, at its top.
    Event::assertDispatched(App\Platform\Modules\Events\ModuleEnabled::class, fn ($event) => $event->organization->is($this->w->g1));
    Event::assertNotDispatched(App\Platform\Modules\Events\ModuleEnabled::class, fn ($event) => $event->organization->is($this->w->c1));
});

it('sets an unlocked default that each client may change, and turns on what it needs', function () {
    $this->asToken($this->ownerToken)->putJson('/api/partner/modules/payroll', ['state' => 'enabled', 'reason' => 'Default for schools'])
        ->assertOk()->assertJsonPath('data.also_enabled', ['attendance', 'hrm']);

    expect(resolvedModule('payroll', $this->w->c1)->enabled)->toBeTrue();

    toggles()->disable($this->w->c1, 'payroll', 'Not this year');
    expect(resolvedModule('payroll', $this->w->c1)->enabled)->toBeFalse()
        ->and(resolvedModule('payroll', $this->w->c2)->enabled)->toBeTrue();
});

it('keeps modules the platform does not let the partner offer away from its clients', function () {
    partnerRule($this->w->partnerA, 'partners.allowed_modules', ['hrm', 'attendance', 'payroll']);

    $this->asToken($this->ownerToken)->putJson('/api/partner/modules/crm', ['state' => 'enabled', 'reason' => 'Try it'])
        ->assertUnprocessable()->assertJsonPath('code', 'module_not_offered');

    expect(resolvedModule('crm', $this->w->c1)->reason->value)->toBe('not_offered');
    $owner = createMember($this->w->c1);
    $this->asToken(orgToken($owner, $this->w->c1))->postJson("/api/organizations/{$this->w->c1->id}/modules/crm/enable", ['reason' => 'We want CRM'])
        ->assertUnprocessable()->assertJsonPath('code', 'not_offered');

    // Partner B is not limited.
    toggles()->enable($this->w->c4, 'crm', 'Partner B client');
    expect(resolvedModule('crm', $this->w->c4)->enabled)->toBeTrue();
});

it('locks a rule for all clients so none can override it', function () {
    toggles()->enable($this->w->c1, 'inventory', 'Stock');

    // A sensitive rule: a second partner owner approves the lock.
    $valueId = $this->asToken($this->ownerToken)->putJson('/api/partner/rules/inventory.valuation_method', ['mode' => 'lock', 'value' => 'FIFO', 'reason' => 'Our accountants use FIFO'])
        ->assertStatus(202)->json('data.id');
    $secondOwner = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA);
    $this->asToken($secondOwner)->postJson("/api/partner/rule-approvals/{$valueId}/approve", ['reason' => 'Agreed with finance'])->assertOk();

    $owner = createMember($this->w->c1);
    $this->asToken(orgToken($owner, $this->w->c1))->putJson("/api/organizations/{$this->w->c1->id}/rules/inventory.valuation_method", ['mode' => 'set', 'value' => 'weighted_average', 'reason' => 'We prefer average'])
        ->assertUnprocessable()->assertJsonPath('code', 'locked_by_parent');

    expect(ruleFor('inventory.valuation_method', $this->w->c1))->toBe('FIFO');
});

it('shows governance rules to the partner but never lets it change them', function () {
    $this->asToken($this->ownerToken)->putJson('/api/partner/rules/partners.max_clients', ['mode' => 'set', 'value' => 1000, 'reason' => 'More clients'])
        ->assertForbidden();

    $rule = collect($this->asToken($this->ownerToken)->getJson('/api/partner/rules')->json('data'))->firstWhere('key', 'partners.max_clients');
    expect($rule['edit_blocked_by'])->toBe('platform_only');
});

it('lets platform operators set a partner\'s governance from the command line', function () {
    $this->artisan('rules:set', ['key' => 'partners.max_clients', 'value' => '7', '--partner' => $this->w->partnerA->slug, '--reason' => 'Reseller contract 2026'])
        ->assertSuccessful();

    expect(app(App\Platform\Rules\RuleResolver::class)->get('partners.max_clients', app(App\Platform\Rules\RuleContextFactory::class)->forPartner($this->w->partnerA)))->toBe(7);
});

it('keeps partner modules away from other partners and the client area', function () {
    $this->asToken($this->ownerToken)->putJson('/api/partner/modules/crm', ['state' => 'disabled', 'lock' => true, 'reason' => 'No CRM'])->assertOk();

    toggles()->enable($this->w->c4, 'crm', 'Partner B client');
    expect(resolvedModule('crm', $this->w->c4)->enabled)->toBeTrue();

    $owner = createMember($this->w->c1);
    $this->asToken(orgToken($owner, $this->w->c1))->putJson('/api/partner/modules/crm', ['state' => 'enabled', 'reason' => 'Self-serve'])->assertForbidden();
});
