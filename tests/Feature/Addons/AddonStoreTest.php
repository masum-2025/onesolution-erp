<?php

use App\Platform\Addons\AddonPriceBook;
use App\Platform\Addons\Addons;
use App\Platform\Addons\Models\OrganizationAddon;
use App\Platform\Audit\AuditLog;
use App\Platform\Billing\Models\Invoice;
use App\Platform\Billing\Services\BillingRun;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Enums\BillingMode;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 * The add-on store: an account (its top organization) tries or buys a
 * module beyond its plan. The module turns on at once; a direct or
 * revenue-share partner's client gets a monthly invoice, a wholesale
 * partner pays on its own monthly invoice. Cancelling runs to the end of
 * the paid month, then the module turns off and its data stays.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 06:00:00', 'UTC'));
    $this->w = tenancyWorld();
    // Partner A bills its clients through us (direct); partner B is wholesale.
    $this->w->partnerA->forceFill(['billing_mode' => BillingMode::Direct])->save();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        setPlan($group, 'starter');   // projects, compliance, fleet... are not in it
    }
    $this->owner = createMember($this->w->g1, MembershipType::Owner);
    $this->token = orgToken($this->owner, $this->w->g1);
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);
    $this->post = fn (string $module, string $action, array $body = [], ?string $token = null, ?string $org = null) => ($this->as)($token)
        ->postJson('/api/organizations/'.($org ?? $this->w->g1->id)."/addons/{$module}/{$action}", $body);
    $this->op = fn () => Str::lower((string) Str::ulid());
    $this->enabled = fn (string $module, $organization) => app(ModuleResolver::class)->fresh($organization->fresh())[$module]->enabled;
    $this->withHeader('Origin', config('app.url'));
});

function storeItem(array $store, string $key): array
{
    return collect($store['items'])->firstWhere('key', $key);
}

it('shows what is in the plan and what can be added, at the account\'s price', function () {
    $store = ($this->as)()->getJson("/api/organizations/{$this->w->g1->id}/addons")->assertOk()->json('data');

    expect($store['open'])->toBeTrue()
        ->and($store['trial_days'])->toBe(14)
        ->and(storeItem($store, 'accounting')['status'])->toBe('in_plan')
        ->and(storeItem($store, 'projects'))->toMatchArray([
            'status' => 'for_sale',
            'price' => ['amount_minor' => 100000, 'currency' => 'BDT'],
            'can_trial' => true,
            'billed_by_provider' => false,
        ]);
});

it('starts a free trial once, turns the module on, and ends it when the days run out', function () {
    ($this->post)('projects', 'trial', ['op_id' => ($this->op)()])->assertCreated()
        ->assertJsonPath('message', __('addons.messages.trial_started'));

    expect(($this->enabled)('projects', $this->w->c1))->toBeTrue()
        ->and(Invoice::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'addons.trial_started')->exists())->toBeTrue();

    $this->travel(15)->days();
    expect(app(Addons::class)->cycle(CarbonImmutable::now()))->toBe(['renewed' => 0, 'ended' => 1]);
    expect(($this->enabled)('projects', $this->w->c1))->toBeFalse()
        ->and(OrganizationAddon::query()->sole()->status)->toBe('ended');

    // A fresh sign-in (the old token ran out meanwhile).
    $this->token = orgToken($this->owner, $this->w->g1);
    ($this->post)('projects', 'trial', ['op_id' => ($this->op)()])->assertUnprocessable()->assertJsonPath('code', 'addon_trial_used');
});

it('buys an add-on: on at once, the month invoiced, a repeated click changes nothing', function () {
    $op = ($this->op)();
    ($this->post)('projects', 'buy', ['op_id' => $op])->assertOk()->assertJsonPath('message', __('addons.messages.bought'));
    ($this->post)('projects', 'buy', ['op_id' => $op])->assertOk();

    $invoice = Invoice::query()->sole();
    expect(($this->enabled)('projects', $this->w->c1))->toBeTrue()
        ->and($invoice->organization_id)->toBe($this->w->g1->id)
        ->and($invoice->currency_code)->toBe('BDT')
        ->and($invoice->subtotal_minor)->toBe(100000)
        ->and($invoice->period_start->toDateString())->toBe('2026-11-03')
        ->and($invoice->period_end->toDateString())->toBe('2026-12-02')
        ->and($invoice->lines[0]->texts('description')['en'])->toContain('add-on, monthly')
        ->and(OrganizationAddon::query()->sole())->status->toBe('active');

    // The next month is billed when this one is over.
    $this->travelTo(CarbonImmutable::parse('2026-12-03 01:00:00', 'UTC'));
    expect(app(Addons::class)->cycle(CarbonImmutable::now()))->toBe(['renewed' => 1, 'ended' => 0]);
    app(Addons::class)->cycle(CarbonImmutable::now());
    expect(Invoice::query()->count())->toBe(2)
        ->and(Invoice::query()->latest('issued_at')->first()->period_start->toDateString())->toBe('2026-12-03');
});

it('turns a trial into a purchase', function () {
    ($this->post)('projects', 'trial', ['op_id' => ($this->op)()])->assertCreated();
    ($this->post)('projects', 'buy', ['op_id' => ($this->op)()])->assertOk();

    expect(OrganizationAddon::query()->sole())->status->toBe('active')->trial_ends_at->toBeNull()
        ->and(Invoice::query()->count())->toBe(1);
});

it('cancels to the end of the paid month, keeps it if asked, then turns it off', function () {
    ($this->post)('projects', 'buy', ['op_id' => ($this->op)()])->assertOk();
    ($this->post)('projects', 'cancel')->assertOk()->assertJsonPath('message', __('addons.messages.cancelled'));
    expect(storeItem(($this->as)()->getJson("/api/organizations/{$this->w->g1->id}/addons")->json('data'), 'projects'))
        ->toMatchArray(['status' => 'active', 'cancel_at_period_end' => true, 'period_end' => '2026-12-02']);

    ($this->post)('projects', 'resume')->assertOk();
    expect(OrganizationAddon::query()->sole()->cancel_at_period_end)->toBeFalse();
    ($this->post)('projects', 'cancel')->assertOk();

    $this->travelTo(CarbonImmutable::parse('2026-12-02 12:00:00', 'UTC'));
    app(Addons::class)->cycle(CarbonImmutable::now());
    expect(($this->enabled)('projects', $this->w->c1))->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-12-03 01:00:00', 'UTC'));
    expect(app(Addons::class)->cycle(CarbonImmutable::now()))->toBe(['renewed' => 0, 'ended' => 1]);
    expect(($this->enabled)('projects', $this->w->c1))->toBeFalse()
        ->and(Invoice::query()->count())->toBe(1);
});

it('asks for the modules an add-on needs first', function () {
    ($this->post)('carbon_management', 'buy', ['op_id' => ($this->op)()])->assertUnprocessable()
        ->assertJsonPath('code', 'addon_needs_first')->assertJsonPath('missing', ['energy_monitoring']);

    ($this->post)('energy_monitoring', 'buy', ['op_id' => ($this->op)()])->assertOk();
    ($this->post)('carbon_management', 'buy', ['op_id' => ($this->op)()])->assertOk();
    expect(($this->enabled)('carbon_management', $this->w->c1))->toBeTrue();
});

it('refuses what is in the plan, unknown modules, a reused click and bad input', function () {
    ($this->post)('accounting', 'buy', ['op_id' => ($this->op)()])->assertUnprocessable()->assertJsonPath('code', 'addon_in_plan');
    ($this->post)('no_such_module', 'buy', ['op_id' => ($this->op)()])->assertNotFound()->assertJsonPath('code', 'addon_unknown');
    ($this->post)('projects', 'buy')->assertUnprocessable()->assertJsonValidationErrors('op_id');
    ($this->post)('projects', 'buy', ['op_id' => ($this->op)(), 'amount_minor' => 1])->assertUnprocessable()->assertJsonValidationErrors('amount_minor');
    ($this->post)('projects', 'cancel')->assertUnprocessable()->assertJsonPath('code', 'addon_not_running');

    $op = ($this->op)();
    ($this->post)('projects', 'buy', ['op_id' => $op])->assertOk();
    ($this->post)('fleet', 'buy', ['op_id' => $op])->assertConflict()->assertJsonPath('code', 'addon_op_reused');
});

it('lets only people holding addons.buy at the top change add-ons', function () {
    $viewer = orgToken(staffWithRoles($this->w->g1, makeRole($this->w->g1, ['billing.view'], 'Billing viewer')), $this->w->g1);
    ($this->as)($viewer)->getJson("/api/organizations/{$this->w->g1->id}/addons")->assertOk()->assertJsonPath('can_buy', false);
    ($this->post)('projects', 'buy', ['op_id' => ($this->op)()], $viewer)->assertForbidden()->assertJsonPath('code', 'addon_not_allowed');

    // A company's administrator does not buy for the whole group.
    $companyAdmin = orgToken(createMember($this->w->c1, MembershipType::Owner), $this->w->c1);
    ($this->as)($companyAdmin)->getJson("/api/organizations/{$this->w->c1->id}/addons")->assertForbidden();
    ($this->post)('projects', 'buy', ['op_id' => ($this->op)()], $companyAdmin, $this->w->c1->id)->assertForbidden();
    expect(OrganizationAddon::query()->count())->toBe(0);
});

it('follows the rules: store closed, provider buys, no trial', function () {
    trustedOrgRule($this->w->g1, 'billing.addons_enabled', false);
    ($this->post)('projects', 'buy', ['op_id' => ($this->op)()])->assertForbidden()->assertJsonPath('code', 'addon_store_closed');
    ($this->as)()->getJson("/api/organizations/{$this->w->g1->id}/addons")->assertJsonPath('data.open', false);
    trustedOrgRule($this->w->g1, 'billing.addons_enabled', true);

    partnerRule($this->w->partnerA, 'billing.addon_trial_days', 0);
    ($this->post)('projects', 'trial', ['op_id' => ($this->op)()])->assertUnprocessable()->assertJsonPath('code', 'addon_no_trial');

    partnerRule($this->w->partnerA, 'billing.addon_self_purchase', false);
    ($this->post)('projects', 'buy', ['op_id' => ($this->op)()])->assertForbidden()->assertJsonPath('code', 'addon_ask_provider');

    // The provider starts it for its client instead.
    $partnerToken = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Billing), $this->w->partnerA);
    $this->asToken($partnerToken)->postJson("/api/partner/clients/{$this->w->g1->id}/addons/projects/buy", ['op_id' => ($this->op)()])->assertOk();
    expect(($this->enabled)('projects', $this->w->c1))->toBeTrue();

    $support = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Support), $this->w->partnerA);
    $this->asToken($support)->postJson("/api/partner/clients/{$this->w->g2->id}/addons/projects/buy", ['op_id' => ($this->op)()])->assertForbidden();
});

it('bills a wholesale partner\'s client through the partner\'s monthly invoice', function () {
    $owner = createMember($this->w->g3, MembershipType::Owner);
    $token = orgToken($owner, $this->w->g3);
    ($this->post)('projects', 'buy', ['op_id' => ($this->op)()], $token, $this->w->g3->id)->assertOk()
        ->assertJsonPath('message', __('addons.messages.bought_provider'));

    expect(Invoice::query()->where('organization_id', $this->w->g3->id)->count())->toBe(0)
        ->and(($this->enabled)('projects', $this->w->c4))->toBeTrue();

    app(BillingRun::class)->run(CarbonImmutable::parse('2026-12-01'), $this->w->partnerB);
    $lines = Invoice::query()->where('partner_id', $this->w->partnerB->id)->sole()->lines;
    $addonLine = $lines->first(fn ($line) => str_contains($line->texts('description')['en'], 'Projects'));
    expect($addonLine)->not->toBeNull()
        ->and($addonLine->amount_minor)->toBe(600);   // USD wholesale (data file)
});

it('uses the partner\'s own price for its clients and keeps every account to its own', function () {
    $owner = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA);
    $this->asToken($owner)->putJson('/api/partner/addons/projects', ['currency' => 'BDT', 'amount_minor' => 120000, 'reason' => 'Our own price'])->assertOk();
    $this->asToken($owner)->getJson('/api/partner/addons')->assertOk();

    expect(app(AddonPriceBook::class)->listPrice($this->w->partnerA, 'projects', 'BDT'))->toBe(120000)
        ->and(app(AddonPriceBook::class)->listPrice($this->w->partnerB, 'projects', 'BDT'))->toBe(100000);

    ($this->post)('projects', 'buy', ['op_id' => ($this->op)()])->assertOk();
    expect(Invoice::query()->sole()->subtotal_minor)->toBe(120000)
        // G1's add-on reaches neither G2 (same partner) nor G3 (another partner).
        ->and(($this->enabled)('projects', $this->w->c3))->toBeFalse()
        ->and(($this->enabled)('projects', $this->w->c4))->toBeFalse();

    // Another partner cannot see or act on partner A's client.
    $other = partnerToken(createPartnerStaff($this->w->partnerB, PartnerUserRole::Owner), $this->w->partnerB);
    $this->asToken($other)->getJson("/api/partner/clients/{$this->w->g1->id}/addons")->assertNotFound();
    $this->asToken($other)->postJson("/api/partner/clients/{$this->w->g1->id}/addons/projects/cancel")->assertNotFound();
    // Nor can a G2 owner reach G1's store.
    $g2 = orgToken(createMember($this->w->g2, MembershipType::Owner), $this->w->g2);
    ($this->as)($g2)->getJson("/api/organizations/{$this->w->g1->id}/addons")->assertNotFound();

    // Sales staff cannot set prices.
    $sales = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Sales), $this->w->partnerA);
    $this->asToken($sales)->putJson('/api/partner/addons/projects', ['currency' => 'BDT', 'amount_minor' => 1, 'reason' => 'Cheaper'])->assertForbidden();
});
