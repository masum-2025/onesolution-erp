<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Billing\Exceptions\BillingException;
use App\Platform\Billing\Models\Commission;
use App\Platform\Billing\Models\Invoice;
use App\Platform\Billing\Money;
use App\Platform\Billing\Services\BillingRun;
use App\Platform\Billing\Services\CreditNotes;
use App\Platform\Billing\Services\InvoicePayments;
use App\Platform\Billing\Services\Payouts;
use App\Platform\Billing\Services\WholesalePriceBook;
use App\Platform\Packaging\Actions\ChangePlan;
use App\Platform\Packaging\Actions\PlanChoice;
use App\Platform\Packaging\Models\Subscription;
use App\Platform\Packaging\Services\PartnerPlanService;
use App\Platform\Packaging\Services\SubscriptionService;
use App\Platform\Tenancy\Enums\BillingMode;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\OrganizationStatus;
use App\Platform\Tenancy\Models\Partner;
use Carbon\CarbonImmutable;

/*
 * Phase 5B-3 acceptance: wholesale and revenue-share invoices calculate
 * correctly, in more than one currency; credit notes, commissions and
 * payouts follow; a month is never billed twice.
 */

beforeEach(function () {
    // Clients created today are billed from next month (no proration).
    $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'UTC'));
    $this->w = tenancyWorld();
    $this->october = CarbonImmutable::parse('2026-10-01', 'UTC');
    $this->run = fn (?Partner $only = null, ?CarbonImmutable $month = null) => app(BillingRun::class)->run($month ?? $this->october, $only);
});

function changePlanTo(object $organization, string|PlanChoice $choice): void
{
    app(ChangePlan::class)->handle($organization->fresh(), $choice, 'Test setup', createMember($organization, MembershipType::Owner));
}

// ── Wholesale ──

it('bills a wholesale partner per client at our wholesale price, in its currency', function () {
    setPlan($this->w->g1, 'business');   // 29.00 USD per client
    setPlan($this->w->g2, 'starter');    //  9.00 USD per client

    $result = ($this->run)($this->w->partnerA);

    $invoice = Invoice::where('partner_id', $this->w->partnerA->id)->sole();
    expect($result->issued)->toBe([$invoice->number])
        ->and($invoice->number)->toBe('INV-2026-000001')
        ->and($invoice->billed_to)->toBe('partner')
        ->and($invoice->organization_id)->toBeNull()
        ->and($invoice->currency_code)->toBe('USD')
        ->and($invoice->subtotal_minor)->toBe(3800)
        ->and($invoice->tax_minor)->toBe(0)
        ->and($invoice->total_minor)->toBe(3800)
        ->and($invoice->period_start->toDateString())->toBe('2026-10-01')
        ->and($invoice->period_end->toDateString())->toBe('2026-10-31')
        ->and($invoice->due_at->toDateString())->toBe('2026-09-29')
        ->and($invoice->lines->pluck('amount_minor')->all())->toBe([2900, 900])
        ->and($invoice->lines[0]->texts('description')['en'])->toBe('G1: Business plan, October 2026')
        ->and($invoice->lines[0]->texts('description')['bn'])->toContain('অক্টোবর');
});

it('bills per staff seat where the wholesale price is per seat', function () {
    setPlan($this->w->g1, 'enterprise');   // 3.00 USD per seat
    createMember($this->w->g1);
    createMember($this->w->c1, MembershipType::Staff);
    createMember($this->w->b1, MembershipType::Staff);
    createMember($this->w->c1, MembershipType::Portal);   // portal users take no seat

    ($this->run)($this->w->partnerA);

    $line = Invoice::where('partner_id', $this->w->partnerA->id)->sole()->lines->firstWhere('organization_id', $this->w->g1->id);
    expect($line->quantity)->toBe(3)->and($line->unit_amount_minor)->toBe(300)->and($line->amount_minor)->toBe(900);
});

it('uses the partner\'s currency, its own price deal and the tax rule', function () {
    partnerRule($this->w->partnerA, 'billing.partner_currency', 'BDT');
    partnerRule($this->w->partnerA, 'billing.tax_rate_bp', 1500);
    setPlan($this->w->g1, 'business');
    setPlan($this->w->g2, 'business');
    app(WholesalePriceBook::class)->set($this->w->partnerA, 'business', 'BDT', 'per_client', 199999, CarbonImmutable::parse('2026-01-01'));

    ($this->run)($this->w->partnerA);

    $invoice = Invoice::where('partner_id', $this->w->partnerA->id)->sole();
    // 2 × 1,999.99 = 3,999.98; 15% = 599.997 → 600.00 (half up).
    expect($invoice->currency_code)->toBe('BDT')
        ->and($invoice->subtotal_minor)->toBe(399998)
        ->and($invoice->tax_rate_bp)->toBe(1500)
        ->and($invoice->tax_minor)->toBe(60000)
        ->and($invoice->total_minor)->toBe(459998);
});

it('reports a client it cannot price and still bills the others', function () {
    partnerRule($this->w->partnerA, 'billing.partner_currency', 'EUR');
    app(WholesalePriceBook::class)->set(null, 'starter', 'EUR', 'per_client', 800, CarbonImmutable::parse('2026-01-01'));
    setPlan($this->w->g1, 'business');   // no EUR wholesale price

    $result = ($this->run)($this->w->partnerA);

    expect($result->skipped)->toHaveCount(1)
        ->and($result->skipped[0]['reason'])->toBe('no wholesale price for G1 on plan business in EUR')
        ->and(Invoice::where('partner_id', $this->w->partnerA->id)->sole()->lines->pluck('organization_id')->all())->toBe([$this->w->g2->id]);
});

it('never bills a month twice and numbers documents without gaps', function () {
    ($this->run)();
    $again = ($this->run)();
    ($this->run)(null, $this->october->addMonth());

    expect($again->issued)->toBe([])
        ->and($again->already)->toBe(2)
        ->and(Invoice::orderBy('number')->pluck('number')->all())->toBe(['INV-2026-000001', 'INV-2026-000002', 'INV-2026-000003', 'INV-2026-000004']);
});

it('skips suspended clients, clients that start later and suspended partners', function () {
    $this->w->g2->forceFill(['status' => OrganizationStatus::Suspended])->save();
    $late = createGroup($this->w->partnerA, 'Joins in October');
    app(SubscriptionService::class)->for($late)->forceFill(['started_on' => '2026-10-10'])->save();

    ($this->run)($this->w->partnerA);
    expect(Invoice::where('partner_id', $this->w->partnerA->id)->sole()->lines->pluck('organization_id')->all())->toBe([$this->w->g1->id]);

    $this->w->partnerB->forceFill(['status' => 'suspended'])->save();
    expect(($this->run)($this->w->partnerB)->issued)->toBe([]);
});

// ── Clients billed directly (revenue share, direct) ──

it('bills revenue-share clients under the partner brand and records the partner\'s share', function () {
    $partner = $this->w->partnerB;
    $partner->forceFill(['billing_mode' => BillingMode::RevenueShare])->save();
    setPlan($this->w->g3, 'business');   // 5,000.00 BDT a month (list price)

    ($this->run)($partner);

    $invoice = Invoice::where('organization_id', $this->w->g3->id)->sole();
    expect($invoice->billed_to)->toBe('organization')
        ->and($invoice->brand_partner_id)->toBe($partner->id)
        ->and($invoice->billing_mode)->toBe('revenue_share')
        ->and($invoice->currency_code)->toBe('BDT')
        ->and($invoice->total_minor)->toBe(500000)
        ->and($invoice->lines[0]->texts('description')['en'])->toBe('Business plan, monthly, 1 Oct 2026 to 31 Oct 2026')
        ->and(Subscription::where('organization_id', $this->w->g3->id)->sole()->billed_through->toDateString())->toBe('2026-10-31')
        // It shows in the client's own audit log.
        ->and(AuditLog::where('action', 'billing.invoice_issued')->where('organization_id', $this->w->g3->id)->exists())->toBeTrue();

    $commission = Commission::sole();
    expect($commission->rate_bp)->toBe(3000)
        ->and($commission->amount_minor)->toBe(150000)
        ->and($commission->status)->toBe('pending');
});

it('bills each client in its own currency and pays commissions per currency', function () {
    $partner = $this->w->partnerB;
    $partner->forceFill(['billing_mode' => BillingMode::RevenueShare])->save();
    partnerRule($partner, 'partners.revenue_share_bp', 2500);
    setPlan($this->w->g3, 'business');
    $usd = createGroup($partner, 'Dubai Branch Office', ['currency_code' => 'USD']);
    changePlanTo($usd, new PlanChoice('business', currency: 'USD'));

    ($this->run)($partner);

    $bdt = Invoice::where('organization_id', $this->w->g3->id)->sole();
    $dollars = Invoice::where('organization_id', $usd->id)->sole();
    expect($dollars->currency_code)->toBe('USD')->and($dollars->total_minor)->toBe(4900);

    app(InvoicePayments::class)->markPaid($bdt, 'BANK-1');
    app(InvoicePayments::class)->markPaid($dollars, 'CARD-1');

    // 25% of 5,000.00 BDT and 25% of 49.00 USD (12.25).
    expect(app(Payouts::class)->payable($partner))->toBe(['BDT' => 125000, 'USD' => 1225]);

    $payout = app(Payouts::class)->record($partner, 'USD', 'WISE-77');
    expect($payout->amount_minor)->toBe(1225)
        ->and(app(Payouts::class)->payable($partner))->toBe(['BDT' => 125000])
        ->and(AuditLog::where('action', 'billing.payout_recorded')->sole()->partner_id)->toBe($partner->id);

    expect(fn () => app(Payouts::class)->record($partner, 'USD', 'WISE-78'))->toThrow(BillingException::class);
});

it('bills a client on a partner plan at the partner\'s price, yearly on its anniversary', function () {
    $partner = $this->w->partnerB;
    $partner->forceFill(['billing_mode' => BillingMode::RevenueShare])->save();
    $plan = app(PartnerPlanService::class)->create($partner, [
        'base_plan_key' => 'business',
        'name' => ['en' => 'Campus', 'bn' => 'ক্যাম্পাস'],
        'prices' => [['currency' => 'BDT', 'period' => 'yearly', 'amount_minor' => 6000000]],
    ], createPartnerStaff($partner));
    changePlanTo($this->w->g3, new PlanChoice('business', $plan, period: 'yearly'));

    ($this->run)($partner);
    ($this->run)($partner, $this->october->addMonth());

    $invoice = Invoice::where('organization_id', $this->w->g3->id)->sole();
    expect($invoice->total_minor)->toBe(6000000)
        ->and($invoice->period_end->toDateString())->toBe('2027-09-30')
        ->and($invoice->lines[0]->texts('description')['en'])->toBe('Campus plan, yearly, 1 Oct 2026 to 30 Sep 2027')
        ->and($invoice->lines[0]->texts('description')['bn'])->toStartWith('ক্যাম্পাস');
});

it('bills the house partner\'s direct clients without any commission', function () {
    $house = Partner::factory()->house()->create();
    $client = createGroup($house, 'Direct Client');
    setPlan($client, 'starter');

    ($this->run)($house);

    expect(Invoice::where('organization_id', $client->id)->sole()->total_minor)->toBe(150000)
        ->and(Commission::count())->toBe(0);
});

// ── Credit notes ──

it('corrects an invoice with credit notes and takes back the partner\'s share', function () {
    $partner = $this->w->partnerB;
    $partner->forceFill(['billing_mode' => BillingMode::RevenueShare])->save();
    partnerRule($partner, 'billing.tax_rate_bp', 1500);
    setPlan($this->w->g3, 'business');
    ($this->run)($partner);
    $invoice = Invoice::where('organization_id', $this->w->g3->id)->sole();
    app(InvoicePayments::class)->markPaid($invoice, 'BANK-9');

    $note = app(CreditNotes::class)->issue($invoice, 100000, 'Two days of downtime');

    expect($note->number)->toBe('CN-2026-000001')
        ->and($note->type)->toBe('credit_note')
        ->and($note->credits_invoice_id)->toBe($invoice->id)
        ->and($note->subtotal_minor)->toBe(100000)
        ->and($note->tax_minor)->toBe(15000)
        ->and($note->reason)->toBe('Two days of downtime')
        ->and($note->buyer)->toBe($invoice->buyer)
        ->and(Commission::where('invoice_id', $note->id)->sole()->amount_minor)->toBe(-30000)
        // The original was paid, so the reversal comes off the next payout.
        ->and(app(Payouts::class)->payable($partner))->toBe(['BDT' => 120000]);

    expect(fn () => app(CreditNotes::class)->issue($invoice, 400001, 'Too much'))
        ->toThrow(fn (BillingException $e) => expect($e->userMessage())->toStartWith('A credit must be between 1 and 400000 (BDT'));

    app(CreditNotes::class)->issue($invoice, 400000, 'Rest of the month');
    expect(fn () => app(CreditNotes::class)->issue($invoice, 1, 'Nothing left'))->toThrow(BillingException::class);
});

it('closes an unpaid invoice that is fully credited', function () {
    $this->w->partnerB->forceFill(['billing_mode' => BillingMode::Direct])->save();
    ($this->run)($this->w->partnerB);
    $invoice = Invoice::where('organization_id', $this->w->g3->id)->sole();

    app(CreditNotes::class)->issue($invoice, $invoice->subtotal_minor, 'Billed in error');

    expect($invoice->fresh()->status)->toBe('credited')
        ->and(fn () => app(InvoicePayments::class)->markPaid($invoice, 'LATE'))->toThrow(BillingException::class);
});

it('rounds money half up without floats', function (int $amount, int $bp, int $expected) {
    expect(Money::share($amount, $bp))->toBe($expected);
})->with([
    [1999, 1500, 300],
    [100, 3333, 33],
    [5, 5000, 3],
    [-5, 5000, -3],
    [0, 1500, 0],
    [999999999999, 10000, 999999999999],
]);
