<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Billing\Models\Invoice;
use App\Platform\Legal\Models\LegalDocument;
use App\Platform\Packaging\Services\SubscriptionService;
use App\Platform\Payments\Models\Payment;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\RuleTargets;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/*
 * A personal workspace becomes a company (Phase 5C-3): same organization,
 * every record kept, a business plan billed without proration, members.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    $this->world = selfServeWorld();
    fakeSslCommerz();
    $this->url = "/api/organizations/{$this->world->workspace->id}/upgrade";
});

function upgradeForm(array $overrides = []): array
{
    return ['name' => ['en' => 'Rahima Traders', 'bn' => 'রহিমা ট্রেডার্স'], 'sector_key' => null, 'plan_key' => 'starter', 'period' => 'monthly', ...$overrides];
}

function asOwner(object $world): TestCase
{
    return test()->asToken(orgToken($world->user, $world->workspace));
}

it('shows what upgrading means before doing it', function () {
    asOwner($this->world)->getJson($this->url)
        ->assertOk()
        ->assertJsonPath('data.suggested_plan', 'starter')
        ->assertJsonPath('data.plans.0.key', 'starter')
        ->assertJsonPath('data.plans.0.prices.monthly', 150000)
        ->assertJsonPath('data.billing_starts_on', '2026-10-05');
});

it('upgrades a free workspace: same organization, company type, business plan, first invoice now', function () {
    $id = $this->world->workspace->id;

    asOwner($this->world)->postJson($this->url, upgradeForm())
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.type', 'company');

    $company = $this->world->workspace->fresh();
    expect($company->type)->toBe(OrganizationType::Company)
        ->and($company->texts('name'))->toEqualCanonicalizing(['en' => 'Rahima Traders', 'bn' => 'রহিমা ট্রেডার্স'])
        ->and($company->plan_key)->toBe('starter');

    // Nothing paid was running: the first business invoice now, due after the payment terms.
    $invoice = Invoice::query()->where('organization_id', $id)->sole();
    expect($invoice->total_minor)->toBe(150000)
        ->and($invoice->period_start->toDateString())->toBe('2026-10-05')
        ->and($invoice->due_at->toDateString())->toBe('2026-10-19')
        ->and(AuditLog::query()->where('action', 'organization.upgraded')->where('organization_id', $id)->exists())->toBeTrue();

    // It stays self-serve: the invoice is paid online like before.
    asOwner($this->world)->getJson("/api/organizations/{$id}/billing/self-serve")
        ->assertOk()
        ->assertJsonPath('data.plan.key', 'starter')
        ->assertJsonPath('data.free_plan_key', null)
        ->assertJsonPath('data.trial_offer', null)
        ->assertJsonPath('data.plans.0.key', 'starter');
});

it('keeps a paid personal period and bills the business plan from the day after it', function () {
    $payment = Payment::query()->findOrFail(startCheckout($this, $this->world)->json('data.id'));
    $this->post('/payments/sslcommerz/notify', sslNotice($payment))->assertOk();
    $receipt = $payment->fresh()->invoice;

    asOwner($this->world)->postJson($this->url, upgradeForm())->assertOk();

    // The earlier receipt is still the organization's; no new invoice yet.
    expect($receipt->fresh()->organization_id)->toBe($this->world->workspace->id)
        ->and(Invoice::query()->count())->toBe(1)
        ->and(app(SubscriptionService::class)->for($this->world->workspace->fresh())->billed_through->toDateString())->toBe('2026-11-04');

    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00', 'UTC'));
    $this->artisan('billing:self-serve')->assertSuccessful();

    $renewal = Invoice::query()->where('billing_key', 'like', 'renewal:%')->sole();
    expect($renewal->total_minor)->toBe(150000)
        ->and($renewal->period_start->toDateString())->toBe('2026-11-05');
});

it('lets the company add members after the upgrade (a personal plan allows one person)', function () {
    $members = "/api/organizations/{$this->world->workspace->id}/members";
    User::factory()->create(['email' => 'karim@example.com']);
    // As in the seed data: a personal plan is for one person, a business plan for a team.
    ruleService()->set(app(RuleTargets::class)->plan('personal_free'), 'plans.max_users', RuleMode::Set, 1, 'Test limit', trusted: true);
    ruleService()->set(app(RuleTargets::class)->plan('starter'), 'plans.max_users', RuleMode::Set, 10, 'Test limit', trusted: true);

    asOwner($this->world)->postJson($members, ['email' => 'karim@example.com', 'membership_type' => 'staff'])->assertStatus(422);

    asOwner($this->world)->postJson($this->url, upgradeForm())->assertOk();
    asOwner($this->world)->postJson($members, ['email' => 'karim@example.com', 'membership_type' => 'staff'])->assertSuccessful();

    expect(OrganizationMembership::query()->where('organization_id', $this->world->workspace->id)->count())->toBe(2);
});

it('asks the new company to accept the DPA too', function () {
    asOwner($this->world)->postJson($this->url, upgradeForm())->assertOk();

    expect(LegalDocument::acceptedKindsFor($this->world->workspace->fresh()))->toContain('dpa');
});

it('lets only the owner upgrade', function () {
    $viewer = staffWithRoles($this->world->workspace, makeRole($this->world->workspace, ['billing.view', 'organizations.manage']));

    $this->asToken(orgToken($viewer, $this->world->workspace))->postJson($this->url, upgradeForm())
        ->assertForbidden()->assertJsonPath('code', 'owner_only');
    expect($this->world->workspace->fresh()->type)->toBe(OrganizationType::Personal);
});

it('refuses what cannot be done, saying why', function () {
    // A personal plan is not a business plan.
    asOwner($this->world)->postJson($this->url, upgradeForm(['plan_key' => 'personal_plus']))->assertStatus(422)->assertJsonPath('code', 'plan_not_offered');

    // The partner turned upgrades off.
    partnerRule($this->world->partner, 'b2c.upgrade_allowed', false);
    asOwner($this->world)->postJson($this->url, upgradeForm())->assertForbidden()->assertJsonPath('code', 'upgrade_closed');
    partnerRule($this->world->partner, 'b2c.upgrade_allowed', true);

    // No client place left at the partner.
    partnerRule($this->world->partner, 'partners.max_clients', 0);
    asOwner($this->world)->postJson($this->url, upgradeForm())->assertStatus(422)->assertJsonPath('code', 'client_limit_reached');

    expect($this->world->workspace->fresh()->type)->toBe(OrganizationType::Personal);
});

it('settles an unpaid invoice first', function () {
    $payment = Payment::query()->findOrFail(startCheckout($this, $this->world)->json('data.id'));
    $this->post('/payments/sslcommerz/notify', sslNotice($payment))->assertOk();
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00', 'UTC'));
    $this->artisan('billing:self-serve')->assertSuccessful();

    asOwner($this->world)->postJson($this->url, upgradeForm())->assertStatus(422)->assertJsonPath('code', 'pay_open_invoice');
});

it('upgrades only once: a company is not a personal workspace', function () {
    asOwner($this->world)->postJson($this->url, upgradeForm())->assertOk();

    asOwner($this->world)->postJson($this->url, upgradeForm())->assertStatus(422)->assertJsonPath('code', 'not_personal');
    // And a company has no free plan to fall back to.
    asOwner($this->world)->postJson("/api/organizations/{$this->world->workspace->id}/billing/free", ['confirm' => true])
        ->assertStatus(422)->assertJsonPath('code', 'no_free_plan');
});

it('rejects unknown fields and a missing name', function () {
    asOwner($this->world)->postJson($this->url, [...upgradeForm(['name' => ['bn' => 'শুধু বাংলা']]), 'type' => 'group'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name.en', 'type']);
});

it('keeps other people out: another person cannot upgrade this workspace', function () {
    $other = selfServeWorld();

    $this->asToken(orgToken($other->user, $other->workspace))->postJson($this->url, upgradeForm())->assertNotFound();
    expect($this->world->workspace->fresh()->type)->toBe(OrganizationType::Personal)
        ->and(MembershipType::Owner)->toBe(OrganizationMembership::query()->where('user_id', $this->world->user->id)->first()->membership_type);
});
