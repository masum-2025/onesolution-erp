<?php

use App\Platform\Payments\GatewayRegistry;
use App\Platform\Payments\GatewayResult;
use App\Platform\Payments\Models\GatewayEvent;
use App\Platform\Payments\Models\Payment;
use App\Platform\Payments\Services\PaymentConfirmer;
use Illuminate\Support\Str;

/*
 * Phase 11: how gateway messages move a payment (critical-path coverage of
 * money): unknown payments, late failures and repeated successes never
 * change money that is already settled.
 */

beforeEach(function () {
    fakeSslCommerz();
    $world = selfServeWorld();
    startCheckout($this, $world)->assertSuccessful();

    $this->payment = Payment::query()->latest('created_at')->firstOrFail();
    $this->gateway = app(GatewayRegistry::class)->forPayment($this->payment);
});

function gatewaySays(object $test, string $status, string $ref, ?int $amount = null): ?Payment
{
    return app(PaymentConfirmer::class)->apply($test->gateway, 'ipn', new GatewayResult(
        paymentId: $test->payment->id,
        status: $status,
        eventRef: $ref,
        gatewayStatus: strtoupper($status),
        amountMinor: $amount ?? $test->payment->amount_minor,
        currency: $test->payment->currency_code,
        gatewayTxn: 'TXN-'.$ref,
    ));
}

it('records a message about a payment it does not know, and changes nothing', function () {
    $result = app(PaymentConfirmer::class)->apply($this->gateway, 'ipn', new GatewayResult(
        paymentId: (string) Str::ulid(), status: GatewayResult::SUCCEEDED, eventRef: 'stranger-1', gatewayStatus: 'VALID', amountMinor: 100, currency: 'BDT',
    ));

    expect($result)->toBeNull()
        ->and(GatewayEvent::query()->where('event_ref', 'stranger-1')->sole()->result)->toBe('unknown_payment')
        ->and($this->payment->fresh()->status)->toBe(Payment::PENDING);
});

it('never lets a late failure undo a settled payment, nor counts a success twice', function () {
    expect(gatewaySays($this, GatewayResult::SUCCEEDED, 'ok-1')->status)->toBe(Payment::SUCCEEDED);

    gatewaySays($this, GatewayResult::FAILED, 'late-failure');
    gatewaySays($this, GatewayResult::SUCCEEDED, 'second-success');

    expect($this->payment->fresh()->status)->toBe(Payment::SUCCEEDED)
        ->and(GatewayEvent::query()->where('event_ref', 'late-failure')->sole()->result)->toBe('no_change')
        ->and(GatewayEvent::query()->where('event_ref', 'second-success')->sole()->result)->toBe('already');
});

it('keeps a pending payment pending when the gateway is still waiting', function () {
    gatewaySays($this, GatewayResult::PENDING, 'wait-1');

    expect($this->payment->fresh()->status)->toBe(Payment::PENDING)
        ->and(GatewayEvent::query()->where('event_ref', 'wait-1')->sole()->result)->toBe('no_change');
});
