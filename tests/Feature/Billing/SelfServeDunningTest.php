<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Billing\Models\Invoice;
use App\Platform\Billing\SelfServe\Events\PaymentOverdue;
use App\Platform\Billing\SelfServe\Events\WorkspaceRestored;
use App\Platform\Billing\SelfServe\Events\WorkspaceRestricted;
use App\Platform\Packaging\Services\SubscriptionService;
use App\Platform\Payments\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
 * Renewals and unpaid bills of self-serve accounts (Phase 5C-2): a renewal
 * invoice before the period ends, reminders after the due date, read-only
 * after the grace period (never deleting anything), and back to normal the
 * moment it is paid or the account moves to the free plan.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    $this->world = selfServeWorld();
    fakeSslCommerz();

    // Bought Personal Plus, monthly: paid for 5 October to 4 November.
    $payment = Payment::query()->findOrFail(startCheckout($this, $this->world)->json('data.id'));
    $this->post('/payments/sslcommerz/notify', sslNotice($payment))->assertOk();
    $this->token = fn () => orgToken($this->world->user, $this->world->workspace);
});

function renewalInvoice(): Invoice
{
    return Invoice::query()->where('billing_key', 'like', 'renewal:%')->sole();
}

function runSelfServe(): void
{
    test()->artisan('billing:self-serve')->assertSuccessful();
}

it('issues the renewal invoice a few days before the period ends, due when the next one starts', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-29 09:00:00', 'UTC'));
    runSelfServe();
    expect(Invoice::query()->where('billing_key', 'like', 'renewal:%')->exists())->toBeFalse();

    // 30 October: 5 days (rule billing.renewal_notice_days) before 4 November.
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00', 'UTC'));
    runSelfServe();
    runSelfServe();

    $invoice = renewalInvoice();
    expect($invoice->total_minor)->toBe(29900)
        ->and($invoice->period_start->toDateString())->toBe('2026-11-05')
        ->and($invoice->period_end->toDateString())->toBe('2026-12-04')
        ->and($invoice->due_at->toDateString())->toBe('2026-11-05')
        ->and(app(SubscriptionService::class)->for($this->world->workspace->fresh())->billed_through->toDateString())->toBe('2026-12-04');
});

it('reminds, then makes the workspace read-only after the grace period, keeping everything', function () {
    Event::fake([PaymentOverdue::class, WorkspaceRestricted::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00', 'UTC'));
    runSelfServe();

    // Due 5 November: first reminder that day, then on day 3 and day 6.
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00', 'UTC'));
    runSelfServe();
    runSelfServe();
    Event::assertDispatchedTimes(PaymentOverdue::class, 1);

    $this->travelTo(CarbonImmutable::parse('2026-11-08 10:00:00', 'UTC'));
    runSelfServe();
    Event::assertDispatchedTimes(PaymentOverdue::class, 2);

    $this->asToken(($this->token)())->getJson("/api/organizations/{$this->world->workspace->id}/billing/self-serve")
        ->assertJsonPath('data.status', 'past_due')
        ->assertJsonPath('data.read_only_from', '2026-11-12T09:00:00+00:00');

    // Day 7 (rule billing.overdue_grace_days): read-only.
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'UTC'));
    runSelfServe();
    Event::assertDispatched(WorkspaceRestricted::class);

    $token = ($this->token)();
    $this->asToken($token)->patchJson("/api/organizations/{$this->world->workspace->id}", ['name' => ['en' => 'New name']])
        ->assertForbidden()
        ->assertJsonPath('code', 'read_only_payment_overdue');

    // Reading, exporting and settling the bill still work.
    $this->asToken($token)->getJson("/api/organizations/{$this->world->workspace->id}")->assertOk();
    $this->asToken($token)->getJson("/api/organizations/{$this->world->workspace->id}/billing/self-serve")
        ->assertOk()->assertJsonPath('data.status', 'read_only');
    $this->asToken($token)->postJson("/api/organizations/{$this->world->workspace->id}/billing/invoices/".renewalInvoice()->id.'/pay', ['op_id' => (string) Str::ulid()])
        ->assertCreated();

    $me = $this->asToken($token)->getJson('/api/me')->assertOk();
    expect($me->json('data.context.mode'))->toBe('read_only')
        ->and($me->json('data.context.mode_reason'))->toBe('payment_overdue')
        ->and($me->json('data.permissions'))->toContain('billing.manage')
        ->and($this->world->workspace->fresh()->status->value)->toBe('active')
        ->and(AuditLog::query()->where('action', 'billing.workspace_restricted')->exists())->toBeTrue();
});

it('works again the moment the overdue invoice is paid', function () {
    Event::fake([WorkspaceRestored::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00', 'UTC'));
    runSelfServe();
    $this->travelTo(CarbonImmutable::parse('2026-11-13 09:00:00', 'UTC'));
    runSelfServe();

    $token = ($this->token)();
    $payment = Payment::query()->findOrFail(
        $this->asToken($token)->postJson("/api/organizations/{$this->world->workspace->id}/billing/invoices/".renewalInvoice()->id.'/pay', ['op_id' => (string) Str::ulid()])->json('data.id')
    );
    $this->post('/payments/sslcommerz/notify', sslNotice($payment))->assertOk();

    Event::assertDispatched(WorkspaceRestored::class);
    expect(renewalInvoice()->status)->toBe(Invoice::PAID);

    $subscription = app(SubscriptionService::class)->for($this->world->workspace->fresh());
    expect($subscription->restricted_at)->toBeNull()->and($subscription->past_due_since)->toBeNull();

    $this->asToken(orgToken($this->world->user, $this->world->workspace))
        ->patchJson("/api/organizations/{$this->world->workspace->id}", ['name' => ['en' => 'New name']])
        ->assertJsonMissing(['code' => 'read_only_payment_overdue']);
});

it('flags a second payment of an already paid invoice for refund, never applying it twice', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00', 'UTC'));
    runSelfServe();
    $url = "/api/organizations/{$this->world->workspace->id}/billing/invoices/".renewalInvoice()->id.'/pay';

    $first = Payment::query()->findOrFail($this->asToken(($this->token)())->postJson($url, ['op_id' => (string) Str::ulid()])->json('data.id'));
    $second = Payment::query()->findOrFail($this->asToken(($this->token)())->postJson($url, ['op_id' => (string) Str::ulid()])->json('data.id'));

    $this->post('/payments/sslcommerz/notify', sslNotice($first))->assertOk();
    $this->post('/payments/sslcommerz/notify', sslNotice($second))->assertOk();

    expect($second->fresh()->refund_due)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'payments.refund_due')->exists())->toBeTrue()
        ->and(renewalInvoice()->payment_reference)->toBe('sslcommerz:VAL-'.$first->id);
});

it('lets a read-only account move to the free plan instead: invoice credited, working again', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00', 'UTC'));
    runSelfServe();
    $this->travelTo(CarbonImmutable::parse('2026-11-13 09:00:00', 'UTC'));
    runSelfServe();

    $this->asToken(($this->token)())->postJson("/api/organizations/{$this->world->workspace->id}/billing/free", [])
        ->assertStatus(422)->assertJsonValidationErrors('confirm');

    $this->asToken(($this->token)())->postJson("/api/organizations/{$this->world->workspace->id}/billing/free", ['confirm' => true])
        ->assertOk()
        ->assertJsonPath('data.status', 'free')
        ->assertJsonPath('data.open_invoices', []);

    expect(renewalInvoice()->status)->toBe(Invoice::CREDITED)
        ->and(Invoice::query()->where('type', Invoice::CREDIT_NOTE)->count())->toBe(1)
        ->and($this->world->workspace->fresh()->plan_key)->toBe('personal_free');

    $this->asToken(orgToken($this->world->user, $this->world->workspace))->getJson('/api/me')
        ->assertJsonPath('data.context.mode', 'normal');
});

it('moves to the free plan at the end of a paid period, and can be kept until then', function () {
    $url = "/api/organizations/{$this->world->workspace->id}/billing";

    $this->asToken(($this->token)())->postJson("{$url}/free", ['confirm' => true])
        ->assertOk()
        ->assertJsonPath('data.moves_to_free_on', '2026-11-05')
        ->assertJsonPath('message', 'You keep your plan until the paid period ends. You move to the free plan on 5 November 2026.');

    $this->asToken(($this->token)())->postJson("{$url}/keep-plan")->assertOk()->assertJsonPath('data.moves_to_free_on', null);
    $this->asToken(($this->token)())->postJson("{$url}/keep-plan")->assertStatus(422)->assertJsonPath('code', 'nothing_to_keep');

    $this->asToken(($this->token)())->postJson("{$url}/free", ['confirm' => true])->assertOk();

    // No renewal invoice for an account that is leaving; it moves on 5 November.
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00', 'UTC'));
    runSelfServe();
    expect(Invoice::query()->where('billing_key', 'like', 'renewal:%')->exists())->toBeFalse()
        ->and($this->world->workspace->fresh()->plan_key)->toBe('personal_plus');

    $this->travelTo(CarbonImmutable::parse('2026-11-05 02:00:00', 'UTC'));
    runSelfServe();
    expect($this->world->workspace->fresh()->plan_key)->toBe('personal_free');
});

it('lets the owner of another account see none of this', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00', 'UTC'));
    runSelfServe();
    $other = selfServeWorld();

    $this->asToken(orgToken($other->user, $other->workspace))
        ->postJson("/api/organizations/{$other->workspace->id}/billing/invoices/".renewalInvoice()->id.'/pay', ['op_id' => (string) Str::ulid()])
        ->assertStatus(422)
        ->assertJsonPath('code', 'invoice_not_payable');
});
