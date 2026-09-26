<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Packaging\Models\PartnerPlan;
use App\Platform\Packaging\Models\Subscription;
use App\Platform\Tenancy\Enums\BillingMode;
use App\Platform\Tenancy\Enums\PartnerUserRole;

/*
 * Phase 5B-3: partners build their own plans on ours (own name and prices,
 * fewer modules, never more) and put clients on them.
 */

beforeEach(function () {
    $this->w = tenancyWorld();
    $this->owner = createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner);
    $this->ownerToken = partnerToken($this->owner, $this->w->partnerA);
});

function newPartnerPlan(object $test, array $overrides = [], ?string $token = null)
{
    return $test->asToken($token ?? $test->ownerToken)->postJson('http://localhost/api/partner/plans', [
        'base_plan_key' => 'business',
        'name' => ['en' => 'School Plus', 'bn' => 'স্কুল প্লাস'],
        'modules' => ['hrm', 'attendance', 'payroll', 'accounting'],
        'prices' => [
            ['currency' => 'BDT', 'period' => 'monthly', 'amount_minor' => 700000],
            ['currency' => 'USD', 'period' => 'monthly', 'amount_minor' => 6900],
        ],
        ...$overrides,
    ]);
}

function assignPlan(object $test, string $clientId, array $body, ?string $token = null)
{
    return $test->asToken($token ?? $test->ownerToken)->putJson("http://localhost/api/partner/organizations/{$clientId}/plan", [
        'reason' => 'Client signed the School Plus offer',
        ...$body,
    ]);
}

it('creates a partner plan with its own name, prices and fewer modules', function () {
    $response = newPartnerPlan($this)->assertCreated()
        ->assertJsonPath('data.name', 'School Plus')
        ->assertJsonPath('data.base_plan.key', 'business')
        ->assertJsonPath('data.modules', ['accounting', 'attendance', 'hrm', 'payroll'])
        ->assertJsonPath('data.clients', 0)
        // A wholesale partner sees what a client on the base plan costs it.
        ->assertJsonPath('data.cost.kind', 'wholesale')
        ->assertJsonPath('data.cost.currency', 'USD')
        ->assertJsonPath('data.cost.amount_minor', 2900);

    expect(AuditLog::where('action', 'partner_plan.created')->sole()->partner_id)->toBe($this->w->partnerA->id);

    $this->asToken($this->ownerToken)->getJson('http://localhost/api/partner/plans')->assertOk()
        ->assertJsonPath('data.0.id', $response->json('data.id'))
        ->assertJsonPath('billing_mode', 'wholesale')
        ->assertJsonPath('can_edit', true)
        ->assertJsonPath('base_plans.1.key', 'business');
});

it('never gives a client more than the base plan or leaves out what a module needs', function (array $overrides, string $code) {
    newPartnerPlan($this, $overrides)->assertUnprocessable()->assertJsonPath('code', $code);
})->with([
    'module outside the base plan' => [['base_plan_key' => 'starter', 'modules' => ['custom_reports']], 'module_not_in_base'],
    'core module' => [['modules' => ['core']], 'module_not_in_base'],
    'payroll without attendance' => [['modules' => ['hrm', 'payroll']], 'module_needs'],
]);

it('keeps modules the platform does not let the partner offer out of its plans', function () {
    partnerRule($this->w->partnerA, 'partners.allowed_modules', ['hrm', 'attendance']);

    newPartnerPlan($this, ['modules' => ['hrm', 'attendance', 'payroll']])->assertUnprocessable()->assertJsonPath('code', 'module_not_in_base');
    newPartnerPlan($this, ['modules' => ['hrm', 'attendance']])->assertCreated();
});

it('validates partner plans', function (array $overrides, string $field) {
    newPartnerPlan($this, $overrides)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'no English name' => [['name' => ['bn' => 'প্ল্যান']], 'name.en'],
    'unknown base plan' => [['base_plan_key' => 'platinum'], 'base_plan_key'],
    'float price' => [['prices' => [['currency' => 'BDT', 'period' => 'monthly', 'amount_minor' => 10.5]]], 'prices.0.amount_minor'],
    'bad currency' => [['prices' => [['currency' => 'taka', 'period' => 'monthly', 'amount_minor' => 100]]], 'prices.0.currency'],
    'same price twice' => [['prices' => [
        ['currency' => 'BDT', 'period' => 'monthly', 'amount_minor' => 100],
        ['currency' => 'BDT', 'period' => 'monthly', 'amount_minor' => 200],
    ]], 'prices.1.currency'],
    'no prices' => [['prices' => []], 'prices'],
    'partner in the body' => [['partner_id' => 'x'], 'partner_id'],
]);

it('lets owners and billing staff change plans, and everyone else only look', function () {
    $billing = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Billing), $this->w->partnerA);
    $sales = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Sales), $this->w->partnerA);

    newPartnerPlan($this, token: $billing)->assertCreated();
    newPartnerPlan($this, token: $sales)->assertForbidden()->assertJsonPath('code', 'role_not_allowed');
    $this->asToken($sales)->getJson('http://localhost/api/partner/plans')->assertOk()->assertJsonPath('can_edit', false);
});

it('turns off the modules a partner plan leaves out for the client on it', function () {
    setPlan($this->w->g1, 'business');
    setPlan($this->w->g2, 'business');
    toggles()->enable($this->w->c1, 'custom_reports', 'Board reports');
    toggles()->enable($this->w->c3, 'custom_reports', 'Board reports');
    toggles()->enable($this->w->c1, 'hrm', 'People');
    $planId = newPartnerPlan($this)->json('data.id');

    assignPlan($this, $this->w->g1->id, ['partner_plan_id' => $planId])->assertOk()
        ->assertJsonPath('data.plan.name', 'School Plus')
        ->assertJsonPath('data.partner_plan.id', $planId)
        ->assertJsonPath('data.modules_off.0.key', 'custom_reports');

    expect(resolvedModule('custom_reports', $this->w->c1)->reason->value)->toBe('not_in_plan')
        ->and(resolvedModule('hrm', $this->w->c1)->enabled)->toBeTrue()
        ->and(Subscription::where('organization_id', $this->w->g1->id)->value('partner_plan_id'))->toBe($planId)
        // Other clients are not affected.
        ->and(resolvedModule('custom_reports', $this->w->c3)->enabled)->toBeTrue();

    $audit = AuditLog::where('action', 'organization.plan_changed')->sole();
    expect($audit->new_values['partner_plan'])->toBe($planId)->and($audit->organization_id)->toBe($this->w->g1->id);
});

it('fixes the modules of a plan that clients are on, but not its name or prices', function () {
    $planId = newPartnerPlan($this)->json('data.id');
    assignPlan($this, $this->w->g1->id, ['partner_plan_id' => $planId])->assertOk();

    $this->asToken($this->ownerToken)->patchJson("http://localhost/api/partner/plans/{$planId}", ['modules' => ['hrm']])
        ->assertUnprocessable()->assertJsonPath('code', 'plan_in_use');

    $this->asToken($this->ownerToken)->patchJson("http://localhost/api/partner/plans/{$planId}", [
        'name' => ['en' => 'School Plus 2026'],
        'prices' => [['currency' => 'BDT', 'period' => 'monthly', 'amount_minor' => 750000]],
    ])->assertOk()->assertJsonPath('data.name', 'School Plus 2026')->assertJsonPath('data.prices.0.amount_minor', 750000);
});

it('archives a plan: clients on it keep it, nobody new can join', function () {
    $planId = newPartnerPlan($this)->json('data.id');
    assignPlan($this, $this->w->g1->id, ['partner_plan_id' => $planId])->assertOk();

    $this->asToken($this->ownerToken)->postJson("http://localhost/api/partner/plans/{$planId}/archive", ['reason' => 'Replaced by the 2027 offer'])
        ->assertOk()->assertJsonPath('data.status', 'archived');

    assignPlan($this, $this->w->g2->id, ['partner_plan_id' => $planId])->assertUnprocessable()->assertJsonPath('code', 'unknown_plan');
    expect(Subscription::where('organization_id', $this->w->g1->id)->value('partner_plan_id'))->toBe($planId);
});

it('sets the currency and period a client is billed in, only where a price exists', function () {
    $this->w->partnerA->forceFill(['billing_mode' => BillingMode::RevenueShare])->save();
    $planId = newPartnerPlan($this)->json('data.id');

    assignPlan($this, $this->w->g1->id, ['partner_plan_id' => $planId, 'currency' => 'USD', 'period' => 'yearly'])
        ->assertUnprocessable()->assertJsonPath('code', 'no_price')
        ->assertJsonPath('message', 'This plan has no price in USD per year. Add one to the plan, or choose another currency or period.');

    assignPlan($this, $this->w->g1->id, ['partner_plan_id' => $planId, 'currency' => 'USD', 'period' => 'monthly'])->assertOk()
        ->assertJsonPath('data.currency', 'USD')
        ->assertJsonPath('data.price_minor', 6900);

    $this->asToken($this->ownerToken)->getJson("http://localhost/api/partner/clients/{$this->w->g1->id}/subscription")->assertOk()
        ->assertJsonPath('data.partner_plan_id', $planId)
        ->assertJsonPath('data.plan_name', 'School Plus')
        ->assertJsonPath('data.price_minor', 6900);
});

it('keeps partner plans apart', function () {
    $planId = newPartnerPlan($this)->json('data.id');
    $otherOwner = partnerToken(createPartnerStaff($this->w->partnerB, PartnerUserRole::Owner), $this->w->partnerB);

    $this->asToken($otherOwner)->patchJson("http://localhost/api/partner/plans/{$planId}", ['name' => ['en' => 'Mine now']])
        ->assertNotFound()->assertJsonPath('code', 'plan_not_found');
    $this->asToken($otherOwner)->getJson('http://localhost/api/partner/plans')->assertOk()->assertJsonCount(0, 'data');
    // Partner B cannot put its own client on Partner A's plan.
    assignPlan($this, $this->w->g3->id, ['partner_plan_id' => $planId], $otherOwner)->assertUnprocessable()->assertJsonPath('code', 'unknown_plan');
    // Nor look at Partner A's client.
    $this->asToken($otherOwner)->getJson("http://localhost/api/partner/clients/{$this->w->g1->id}/subscription")->assertNotFound();

    expect(PartnerPlan::find($planId)->name['en'])->toBe('School Plus');
});
