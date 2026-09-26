<?php

use App\Platform\Billing\Models\Invoice;
use App\Platform\Billing\Models\WholesalePrice;
use App\Platform\Billing\Services\BillingRun;
use App\Platform\Tenancy\Enums\BillingMode;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Carbon\CarbonImmutable;

/*
 * Phase 5B-3: who sees which billing documents. Clients see their own plan
 * and invoices (billing.view at the top organization); partner owners and
 * billing staff see their own documents; nobody reaches another partner's.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'UTC'));
    $this->w = tenancyWorld();
    // Partner A: wholesale. Partner B: revenue share.
    $this->w->partnerB->forceFill(['billing_mode' => BillingMode::RevenueShare])->save();
    setPlan($this->w->g3, 'business');
    app(BillingRun::class)->run(CarbonImmutable::parse('2026-10-01', 'UTC'));

    $this->wholesaleInvoice = Invoice::where('partner_id', $this->w->partnerA->id)->sole();
    $this->clientInvoice = Invoice::where('organization_id', $this->w->g3->id)->sole();
    $this->g3Owner = createMember($this->w->g3);
    $this->ownerA = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Owner), $this->w->partnerA);
    $this->ownerB = partnerToken(createPartnerStaff($this->w->partnerB, PartnerUserRole::Owner), $this->w->partnerB);
});

// ── Clients ──

it('shows a revenue-share client its plan, price and invoices', function () {
    $this->asToken(orgToken($this->g3Owner, $this->w->g3))->getJson("/api/organizations/{$this->w->g3->id}/billing")->assertOk()
        ->assertJsonPath('data.plan_name', 'Business')
        ->assertJsonPath('data.currency', 'BDT')
        ->assertJsonPath('data.price_minor', 500000)
        ->assertJsonPath('data.billed_through', '2026-10-31')
        ->assertJsonPath('data.billed_by_provider', false)
        ->assertJsonPath('data.invoices.0.number', $this->clientInvoice->number)
        ->assertJsonPath('data.invoices.0.status', 'issued');

    $this->getJson("/api/organizations/{$this->w->g3->id}/billing/invoices/{$this->clientInvoice->id}")->assertOk()
        ->assertJsonPath('data.lines.0.amount_minor', 500000)
        ->assertJsonPath('data.brand.name', $this->w->partnerB->name)
        ->assertJsonPath('data.buyer_details.name', 'G3')
        ->assertJsonPath('data.seller.name', 'One Solutions');
});

it('tells a wholesale client its provider bills it, and shows no price', function () {
    $owner = createMember($this->w->g1);

    $this->asToken(orgToken($owner, $this->w->g1))->getJson("/api/organizations/{$this->w->g1->id}/billing")->assertOk()
        ->assertJsonPath('data.billed_by_provider', true)
        ->assertJsonPath('data.price_minor', null)
        ->assertJsonPath('data.invoices', []);
});

it('shows an overdue invoice as overdue', function () {
    $this->travel(20)->days();

    $this->asToken(orgToken($this->g3Owner, $this->w->g3))->getJson("/api/organizations/{$this->w->g3->id}/billing")
        ->assertJsonPath('data.invoices.0.status', 'overdue');
});

it('keeps billing to people with billing.view at the top organization', function () {
    $staff = createMember($this->w->g3, MembershipType::Staff);
    $companyOwner = createMember($this->w->c4);

    $this->asToken(orgToken($staff, $this->w->g3))->getJson("/api/organizations/{$this->w->g3->id}/billing")
        ->assertForbidden()->assertJsonPath('code', 'top_level_only');
    // An owner of a company inside the group cannot see the group's billing either.
    $this->asToken(orgToken($companyOwner, $this->w->c4))->getJson("/api/organizations/{$this->w->c4->id}/billing")
        ->assertForbidden()->assertJsonPath('message', 'Billing belongs to G3. Ask someone who manages it.');

    $billingRole = makeRole($this->w->g3, ['billing.view'], 'Accounts');
    $this->asToken(orgToken(staffWithRoles($this->w->g3, $billingRole), $this->w->g3))->getJson("/api/organizations/{$this->w->g3->id}/billing")->assertOk();
});

it('never shows one client another client\'s invoice', function () {
    $owner = createMember($this->w->g1);

    $this->asToken(orgToken($owner, $this->w->g1))->getJson("/api/organizations/{$this->w->g1->id}/billing/invoices/{$this->clientInvoice->id}")
        ->assertNotFound()->assertJsonPath('code', 'invoice_not_found');
    $this->asToken(orgToken($owner, $this->w->g1))->getJson("/api/organizations/{$this->w->g3->id}/billing")->assertNotFound();
});

// ── Partners ──

it('shows a wholesale partner the invoices we send it', function () {
    $this->asToken($this->ownerA)->getJson('/api/partner/billing')->assertOk()
        ->assertJsonPath('data.billing_mode', 'wholesale')
        ->assertJsonPath('data.we_bill_you.0.currency', 'USD')
        ->assertJsonPath('data.we_bill_you.0.total_minor', $this->wholesaleInvoice->total_minor);

    $this->getJson('/api/partner/billing/invoices')->assertOk()->assertJsonPath('data.0.number', $this->wholesaleInvoice->number)->assertJsonCount(1, 'data');
    $this->getJson("/api/partner/billing/invoices/{$this->wholesaleInvoice->id}")->assertOk()->assertJsonCount(2, 'data.lines');
});

it('shows a revenue-share partner its clients\' invoices and its commissions', function () {
    $this->asToken($this->ownerB)->getJson('/api/partner/billing')->assertOk()
        ->assertJsonPath('data.revenue_share_bp', 3000)
        ->assertJsonPath('data.clients_owe.0.total_minor', 500000)
        ->assertJsonPath('data.commissions.pending.0.total_minor', 150000);

    $this->getJson('/api/partner/billing/invoices?billed_to=organization')->assertOk()->assertJsonPath('data.0.buyer', 'G3');
    $this->getJson('/api/partner/billing/commissions')->assertOk()
        ->assertJsonPath('data.0.client', 'G3')
        ->assertJsonPath('data.0.amount_minor', 150000)
        ->assertJsonPath('data.0.status', 'pending');
});

it('keeps partner billing to owners and billing staff, and to the partner\'s own documents', function () {
    $sales = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Sales), $this->w->partnerA);
    $billing = partnerToken(createPartnerStaff($this->w->partnerA, PartnerUserRole::Billing), $this->w->partnerA);

    $this->asToken($sales)->getJson('/api/partner/billing/invoices')->assertForbidden()->assertJsonPath('code', 'role_not_allowed');
    $this->asToken($billing)->getJson('/api/partner/billing/invoices')->assertOk();

    // Partner B cannot open Partner A's invoice, nor A its client's.
    $this->asToken($this->ownerB)->getJson("/api/partner/billing/invoices/{$this->wholesaleInvoice->id}")->assertNotFound();
    $this->asToken($this->ownerA)->getJson("/api/partner/billing/invoices/{$this->clientInvoice->id}")->assertNotFound();
    $this->asToken($this->ownerA)->getJson('/api/partner/billing/commissions')->assertOk()->assertJsonCount(0, 'data');
});

// ── Platform commands ──

it('runs billing, records payments, credits and payouts from the command line', function () {
    $this->artisan('billing:run', ['--month' => '2026-10'])
        ->expectsOutputToContain('2026-10: 0 issued, 2 already issued, 0 could not be billed.')
        ->assertSuccessful();

    $this->artisan('billing:mark-paid', ['number' => $this->clientInvoice->number, '--reference' => 'BANK-42'])->assertSuccessful();
    $this->artisan('billing:mark-paid', ['number' => $this->clientInvoice->number, '--reference' => 'BANK-42'])
        ->expectsOutputToContain('Only an unpaid invoice can be marked paid.')->assertFailed();

    $this->artisan('billing:credit', ['number' => $this->clientInvoice->number, '--amount' => '50000', '--reason' => 'Goodwill credit'])
        ->expectsOutputToContain('CN-2026-000001')->assertSuccessful();
    $this->artisan('billing:credit', ['number' => $this->clientInvoice->number, '--amount' => '1.5', '--reason' => 'Goodwill credit'])->assertExitCode(2);

    $this->artisan('billing:payout', ['partner' => $this->w->partnerB->slug, 'currency' => 'bdt', '--reference' => 'TRX-1'])
        ->expectsOutputToContain('Paid 135000 BDT')->assertSuccessful();
});

it('sets a wholesale price for one partner without touching the others', function () {
    $this->artisan('billing:wholesale-price', [
        'plan' => 'business', 'currency' => 'USD', 'amount' => '2500',
        '--partner' => $this->w->partnerA->slug, '--from' => '2026-11-01', '--reason' => '2027 contract',
    ])->assertSuccessful();
    $this->artisan('billing:wholesale-price', ['plan' => 'platinum', 'currency' => 'USD', 'amount' => '1', '--reason' => 'Nope nope'])->assertExitCode(2);

    setPlan($this->w->g1, 'business');
    setPlan($this->w->g2, 'business');
    app(BillingRun::class)->run(CarbonImmutable::parse('2026-11-01', 'UTC'));

    expect(Invoice::where('partner_id', $this->w->partnerA->id)->where('period_start', '2026-11-01')->sole()->lines->pluck('unit_amount_minor')->all())->toBe([2500, 2500])
        // The older price stays on record.
        ->and(WholesalePrice::where('plan_key', 'business')->where('currency_code', 'USD')->count())->toBe(2);
});
