<?php

use App\Models\User;
use App\Platform\Audit\AuditLog;
use App\Platform\Billing\Models\Commission;
use App\Platform\Billing\Models\Invoice;
use App\Platform\Billing\Services\BillingRun;
use App\Platform\Packaging\Services\SubscriptionService;
use App\Platform\Payments\Models\GatewayEvent;
use App\Platform\Payments\Models\Payment;
use App\Platform\Tenancy\Enums\BillingMode;
use App\Platform\Tenancy\Models\Partner;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * Self-serve checkout (Phase 5C-2): a person buys a personal plan online.
 * The server fixes the amount, SSLCommerz (sandbox) takes the money, and
 * only SSLCommerz's own validation of the transaction unlocks the plan.
 */

beforeEach(function () {
    $this->world = selfServeWorld();
    fakeSslCommerz();
});

it('prices a plan before buying: integer amounts, tax at the account rate, the period', function () {
    platformRule('billing.tax_rate_bp', 1500);

    $this->asToken(orgToken($this->world->user, $this->world->workspace))
        ->postJson("/api/organizations/{$this->world->workspace->id}/billing/quote", ['plan_key' => 'personal_plus', 'period' => 'yearly'])
        ->assertOk()
        ->assertJsonPath('data.currency', 'BDT')
        ->assertJsonPath('data.subtotal_minor', 299000)
        ->assertJsonPath('data.tax_minor', 44850)
        ->assertJsonPath('data.total_minor', 343850)
        ->assertJsonPath('data.starts_on', CarbonImmutable::now('UTC')->toDateString())
        ->assertJsonPath('data.ends_on', CarbonImmutable::now('UTC')->addYearNoOverflow()->subDay()->toDateString());
});

it('buys a plan end to end: gateway page, verified notice, paid invoice, plan and period', function () {
    $response = startCheckout($this, $this->world)
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.amount_minor', 29900);

    $payment = Payment::query()->findOrFail($response->json('data.id'));
    expect($response->json('data.checkout_url'))->toStartWith('https://sandbox.sslcommerz.com/');

    // The gateway got our amount as decimal text, our id, and our callback addresses.
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/gwprocess/v4/api.php')
        && $request['total_amount'] === '299.00'
        && $request['currency'] === 'BDT'
        && $request['tran_id'] === $payment->getKey()
        && str_ends_with($request['ipn_url'], '/payments/sslcommerz/notify')
        && $request['store_passwd'] === SSL_STORE_PASSWORD);

    // Nothing changes before the gateway confirms.
    expect($this->world->workspace->fresh()->plan_key)->toBe('personal_free')
        ->and(Invoice::query()->count())->toBe(0);

    $this->post('/payments/sslcommerz/notify', sslNotice($payment))->assertOk();

    $payment->refresh();
    $invoice = $payment->invoice;
    $subscription = app(SubscriptionService::class)->for($this->world->workspace->fresh());

    expect($payment->status)->toBe(Payment::SUCCEEDED)
        ->and($payment->method)->toBe('BKASH-BKash')
        ->and($invoice->status)->toBe(Invoice::PAID)
        ->and($invoice->total_minor)->toBe(29900)
        ->and($invoice->payment_reference)->toBe('sslcommerz:VAL-'.$payment->getKey())
        ->and($this->world->workspace->fresh()->plan_key)->toBe('personal_plus')
        ->and($subscription->billed_through->toDateString())->toBe(CarbonImmutable::now('UTC')->addMonthNoOverflow()->subDay()->toDateString())
        ->and(AuditLog::query()->where('action', 'payments.succeeded')->where('organization_id', $this->world->workspace->id)->exists())->toBeTrue();

    // No card details are kept from the gateway's messages.
    expect(GatewayEvent::query()->get()->pluck('payload')->flatten()->implode(' '))->not->toContain('XXXXXX');
});

it('applies a repeated notice once', function () {
    $payment = Payment::query()->findOrFail(startCheckout($this, $this->world)->json('data.id'));

    $this->post('/payments/sslcommerz/notify', sslNotice($payment))->assertOk();
    $this->post('/payments/sslcommerz/notify', sslNotice($payment))->assertOk();
    // The browser coming back with the same transaction changes nothing either.
    $this->post('/payments/sslcommerz/return/success', sslNotice($payment))->assertRedirect("/billing/payments/{$payment->id}");

    expect(Invoice::query()->count())->toBe(1)
        ->and(GatewayEvent::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'payments.succeeded')->count())->toBe(1);
});

it('confirms from the browser return alone (a notice may never arrive)', function () {
    $payment = Payment::query()->findOrFail(startCheckout($this, $this->world)->json('data.id'));

    $this->post('/payments/sslcommerz/return/success', sslNotice($payment))
        ->assertStatus(303)
        ->assertRedirect("/billing/payments/{$payment->id}")
        // No session is started on the way back: the person's own session cookie stays.
        ->assertCookieMissing(config('session.cookie'));

    expect($payment->fresh()->status)->toBe(Payment::SUCCEEDED);
});

it('gives the same payment and page for a repeated click', function () {
    $opId = (string) Str::ulid();

    $first = startCheckout($this, $this->world, opId: $opId)->assertCreated();
    startCheckout($this, $this->world, opId: $opId)->assertOk()->assertJsonPath('data.id', $first->json('data.id'))
        ->assertJsonPath('data.checkout_url', $first->json('data.checkout_url'));

    // Reusing the click for something else is refused.
    startCheckout($this, $this->world, period: 'yearly', opId: $opId)->assertStatus(409)->assertJsonPath('code', 'op_reused');

    expect(Payment::query()->count())->toBe(1);
    Http::assertSentCount(1);
});

it('holds a payment whose confirmed amount differs, and unlocks nothing', function () {
    fakeSslCommerz(['currency_amount' => '1.00', 'amount' => '1.00']);
    $payment = Payment::query()->findOrFail(startCheckout($this, $this->world)->json('data.id'));

    $this->post('/payments/sslcommerz/notify', sslNotice($payment))->assertOk();

    expect($payment->fresh()->status)->toBe(Payment::REVIEW)
        ->and($payment->fresh()->failure_code)->toBe('amount_mismatch')
        ->and($this->world->workspace->fresh()->plan_key)->toBe('personal_free')
        ->and(Invoice::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'payments.held')->exists())->toBeTrue();
});

it('holds a payment the gateway flags as risky', function () {
    fakeSslCommerz(['risk_level' => '1', 'risk_title' => 'Card is risky']);
    $payment = Payment::query()->findOrFail(startCheckout($this, $this->world)->json('data.id'));

    $this->post('/payments/sslcommerz/notify', sslNotice($payment))->assertOk();

    expect($payment->fresh()->status)->toBe(Payment::REVIEW)
        ->and($payment->fresh()->failure_code)->toBe('gateway_risk')
        ->and($this->world->workspace->fresh()->plan_key)->toBe('personal_free');
});

it('ignores forged notices', function () {
    $payment = Payment::query()->findOrFail(startCheckout($this, $this->world)->json('data.id'));

    // A "failed" notice signed with the wrong password.
    $this->post('/payments/sslcommerz/notify', sslNotice($payment, 'FAILED', password: 'guess'))->assertStatus(400);

    // A "valid" notice the gateway's validation does not confirm.
    fakeSslCommerz(['status' => 'INVALID_TRANSACTION']);
    $this->post('/payments/sslcommerz/notify', sslNotice($payment))->assertStatus(400);

    // A "valid" notice for another transaction than the one validated.
    fakeSslCommerz(['tran_id' => (string) Str::ulid()]);
    $this->post('/payments/sslcommerz/notify', sslNotice($payment))->assertStatus(400);

    expect($payment->fresh()->status)->toBe(Payment::PENDING)
        ->and($this->world->workspace->fresh()->plan_key)->toBe('personal_free');
});

it('closes a payment on a genuine failure notice, and a later success still counts', function () {
    $payment = Payment::query()->findOrFail(startCheckout($this, $this->world)->json('data.id'));

    $this->post('/payments/sslcommerz/notify', sslNotice($payment, 'FAILED'))->assertOk();
    expect($payment->fresh()->status)->toBe(Payment::FAILED);

    // The person retried on the same page and paid: the money was taken, so it applies.
    $this->post('/payments/sslcommerz/notify', sslNotice($payment))->assertOk();
    expect($payment->fresh()->status)->toBe(Payment::SUCCEEDED)
        ->and($this->world->workspace->fresh()->plan_key)->toBe('personal_plus');
});

it('finds a confirmed payment by asking the gateway when no notice came', function () {
    $payment = Payment::query()->findOrFail(startCheckout($this, $this->world)->json('data.id'));
    fakeSslCommerz(lookup: [[
        'status' => 'VALID', 'tran_id' => $payment->id, 'val_id' => 'VAL-'.$payment->id,
        'amount' => '299.00', 'currency_type' => 'BDT', 'currency_amount' => '299.00', 'card_type' => 'VISA-Dutch Bangla', 'risk_level' => '0',
    ]]);

    $this->travel(1)->minutes();
    $this->asToken(orgToken($this->world->user, $this->world->workspace))
        ->getJson("/api/organizations/{$this->world->workspace->id}/billing/payments/{$payment->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.checkout_url', null);
});

it('expires abandoned payments', function () {
    $payment = Payment::query()->findOrFail(startCheckout($this, $this->world)->json('data.id'));

    $this->travel(31)->minutes();
    $this->artisan('payments:reconcile')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(Payment::EXPIRED);
});

it('says so when the gateway cannot open a page, and charges nothing', function () {
    fakeSslCommerz(initFails: true);

    startCheckout($this, $this->world)->assertStatus(503)->assertJsonPath('code', 'gateway_unavailable');

    expect(Payment::query()->first()->status)->toBe(Payment::FAILED);
});

it('asks for a confirmed email or phone before paying', function () {
    $this->world->user->forceFill(['email_verified_at' => null, 'phone_verified_at' => null])->save();

    startCheckout($this, $this->world)->assertStatus(422)->assertJsonPath('code', 'unverified');
    expect(Payment::query()->count())->toBe(0);
});

it('explains when no online payment is offered for the country', function () {
    $abroad = selfServeWorld(country: 'US');

    startCheckout($this, $abroad)->assertStatus(422)->assertJsonPath('code', 'no_gateway');
});

it('refuses plans that are not sold here: business plans, free plans, unknown ones', function (string $plan) {
    startCheckout($this, $this->world, plan: $plan)->assertStatus(422);
})->with(['business', 'personal_free', 'no_such_plan']);

it('does not sell a second period in the middle of a paid one', function () {
    $payment = Payment::query()->findOrFail(startCheckout($this, $this->world)->json('data.id'));
    $this->post('/payments/sslcommerz/notify', sslNotice($payment))->assertOk();

    startCheckout($this, $this->world)->assertStatus(422)->assertJsonPath('code', 'already_on_plan');
    startCheckout($this, $this->world, period: 'yearly')->assertStatus(422)->assertJsonPath('code', 'change_at_period_end');
});

it('rejects unknown fields and missing op ids', function () {
    $this->asToken(orgToken($this->world->user, $this->world->workspace))
        ->postJson("/api/organizations/{$this->world->workspace->id}/billing/checkout", [
            'plan_key' => 'personal_plus', 'period' => 'monthly', 'amount_minor' => 1,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['op_id', 'amount_minor']);
});

it('lets only people who manage billing buy', function () {
    $viewer = staffWithRoles($this->world->workspace, makeRole($this->world->workspace, ['billing.view']));

    $this->asToken(orgToken($viewer, $this->world->workspace))
        ->getJson("/api/organizations/{$this->world->workspace->id}/billing/self-serve")
        ->assertOk()
        ->assertJsonPath('data.can_manage', false);

    $this->asToken(orgToken($viewer, $this->world->workspace))
        ->postJson("/api/organizations/{$this->world->workspace->id}/billing/checkout", ['plan_key' => 'personal_plus', 'period' => 'monthly', 'op_id' => (string) Str::ulid()])
        ->assertForbidden()
        ->assertJsonPath('code', 'not_allowed');
});

it('keeps accounts apart: another person\'s payment and workspace are out of reach', function () {
    $payment = Payment::query()->findOrFail(startCheckout($this, $this->world)->json('data.id'));
    $other = selfServeWorld();
    $token = orgToken($other->user, $other->workspace);

    $this->asToken($token)->getJson("/api/organizations/{$other->workspace->id}/billing/payments/{$payment->id}")
        ->assertNotFound()->assertJsonPath('code', 'payment_not_found');
    $this->asToken($token)->getJson("/api/organizations/{$this->world->workspace->id}/billing/self-serve")->assertNotFound();
    $this->asToken($token)->postJson("/api/organizations/{$this->world->workspace->id}/billing/checkout", ['plan_key' => 'personal_plus', 'period' => 'monthly', 'op_id' => (string) Str::ulid()])
        ->assertNotFound();
});

it('keeps partners apart: a white-label partner\'s person cannot reach house payments', function () {
    $payment = Payment::query()->findOrFail(startCheckout($this, $this->world)->json('data.id'));
    $acme = Partner::factory()->create(['name' => 'Acme', 'billing_mode' => BillingMode::RevenueShare]);
    $acmePerson = selfServeWorld($acme);

    $this->asToken(orgToken($acmePerson->user, $acmePerson->workspace))
        ->getJson("/api/organizations/{$acmePerson->workspace->id}/billing/payments/{$payment->id}")
        ->assertNotFound();
});

it('records the partner\'s commission on a revenue-share sale', function () {
    $acme = Partner::factory()->create(['name' => 'Acme', 'billing_mode' => BillingMode::RevenueShare]);
    $world = selfServeWorld($acme);

    $payment = Payment::query()->findOrFail(startCheckout($this, $world)->json('data.id'));
    $this->post('/payments/sslcommerz/notify', sslNotice($payment))->assertOk();

    $commission = Commission::query()->where('partner_id', $acme->id)->firstOrFail();
    expect($commission->amount_minor)->toBe(8970)
        ->and($payment->fresh()->invoice->brand_partner_id)->toBe($acme->id);
});

it('leaves plans a wholesale partner sells to that partner', function () {
    $world = selfServeWorld(Partner::factory()->create(['billing_mode' => BillingMode::Wholesale]));

    startCheckout($this, $world)->assertStatus(422)->assertJsonPath('code', 'billed_by_provider');
});

it('is left out of the monthly billing run', function () {
    $payment = Payment::query()->findOrFail(startCheckout($this, $this->world)->json('data.id'));
    $this->post('/payments/sslcommerz/notify', sslNotice($payment))->assertOk();

    $this->travel(2)->months();
    app(BillingRun::class)->run(CarbonImmutable::now());

    expect(Invoice::query()->count())->toBe(1);
});

it('sends a receipt, not an invoice notice, for a checkout', function () {
    Illuminate\Support\Facades\Bus::fake([App\Platform\Notifications\Jobs\DeliverNotification::class]);
    $payment = Payment::query()->findOrFail(startCheckout($this, $this->world)->json('data.id'));

    $this->post('/payments/sslcommerz/notify', sslNotice($payment))->assertOk();

    $keys = App\Platform\Notifications\Models\NotificationDelivery::query()->pluck('notification_key')->unique()->values()->all();

    expect($keys)->toContain('billing.payment_received')->not->toContain('billing.invoice_issued');
});

it('shows where the account stands', function () {
    $this->asToken(orgToken($this->world->user, $this->world->workspace))
        ->getJson("/api/organizations/{$this->world->workspace->id}/billing/self-serve")
        ->assertOk()
        ->assertJsonPath('data.status', 'free')
        ->assertJsonPath('data.plan.key', 'personal_free')
        ->assertJsonPath('data.can_pay_online', true)
        ->assertJsonPath('data.can_manage', true)
        ->assertJsonPath('data.trial_offer.plan_key', 'personal_plus')
        ->assertJsonPath('data.plans.1.key', 'personal_plus')
        ->assertJsonPath('data.plans.1.prices.monthly', 29900);
});

it('never takes sandbox payments in production', function () {
    app()->detectEnvironment(fn () => 'production');
    app()->forgetInstance(App\Platform\Payments\GatewayRegistry::class);

    startCheckout($this, $this->world)->assertStatus(422)->assertJsonPath('code', 'no_gateway');
    $this->post('/payments/sslcommerz/notify', [])->assertNotFound();
});
