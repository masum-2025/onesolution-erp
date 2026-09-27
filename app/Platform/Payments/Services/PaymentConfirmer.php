<?php

namespace App\Platform\Payments\Services;

use App\Platform\Audit\AuditLogger;
use App\Platform\Payments\Contracts\PaymentFulfiller;
use App\Platform\Payments\Contracts\PaymentGateway;
use App\Platform\Payments\Events\PaymentFailed;
use App\Platform\Payments\Events\PaymentSucceeded;
use App\Platform\Payments\GatewayResult;
use App\Platform\Payments\Models\GatewayEvent;
use App\Platform\Payments\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Applies what a gateway confirmed to our payment, exactly once:
 *
 * - every gateway message is stored once per gateway reference; a repeat
 *   finds the processed record and changes nothing;
 * - success needs the amount and currency we fixed at the start; anything
 *   else (or a gateway risk flag) holds the payment for review and unlocks
 *   nothing;
 * - failures and cancellations only close a pending payment;
 * - the purchase is fulfilled in the same transaction as the status change;
 * - a message checked with one account's credentials (the platform's, or a
 *   client's merchant account) only ever applies to that account's payments.
 */
class PaymentConfirmer
{
    public function __construct(private PaymentFulfiller $fulfiller, private AuditLogger $audit) {}

    /**
     * @param  string|null  $merchantAccountId  Whose credentials checked the message (null: the platform's).
     */
    public function apply(PaymentGateway $gateway, string $kind, GatewayResult $result, ?string $merchantAccountId = null): ?Payment
    {
        $event = $this->event($gateway, $kind, $result);
        if ($event->processed_at !== null) {
            return $event->payment_id === null ? null : Payment::query()->find($event->payment_id);
        }

        $payment = Payment::query()->whereKey($result->paymentId)->where('gateway', $gateway->key())
            ->where('merchant_account_id', $merchantAccountId)
            ->first();
        if ($payment === null) {
            $event->forceFill(['result' => 'unknown_payment', 'processed_at' => CarbonImmutable::now()])->save();

            return null;
        }

        [$payment, $outcome] = DB::transaction(function () use ($payment, $result, $event) {
            $payment = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            // The same message may have been applied while this one waited for the lock.
            $event = GatewayEvent::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();
            if ($event->processed_at !== null) {
                return [$payment, 'duplicate'];
            }

            $outcome = $this->transition($payment, $result);

            $event->forceFill(['payment_id' => $payment->getKey(), 'result' => $outcome, 'processed_at' => CarbonImmutable::now()])->save();

            return [$payment, $outcome];
        });

        if ($outcome === Payment::SUCCEEDED) {
            PaymentSucceeded::dispatch($payment);
        } elseif (in_array($outcome, [Payment::FAILED, Payment::CANCELLED], true)) {
            PaymentFailed::dispatch($payment);
        }

        return $payment;
    }

    /** Gives up a payment the gateway never completed. */
    public function expire(Payment $payment): Payment
    {
        return DB::transaction(function () use ($payment) {
            $payment = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            if ($payment->isPending()) {
                $payment->forceFill(['status' => Payment::EXPIRED, 'failure_code' => 'expired'])->save();
            }

            return $payment;
        });
    }

    private function transition(Payment $payment, GatewayResult $result): string
    {
        if (! $result->succeeded()) {
            if (! $payment->isPending() || $result->status === GatewayResult::PENDING) {
                return 'no_change';
            }

            $status = $result->status === GatewayResult::CANCELLED ? Payment::CANCELLED : Payment::FAILED;
            $payment->forceFill(['status' => $status, 'failure_code' => mb_strtolower($result->gatewayStatus)])->save();

            return $status;
        }

        if (! $payment->canSucceed()) {
            return 'already';
        }

        $problem = match (true) {
            $result->amountMinor !== $payment->amount_minor || $result->currency !== $payment->currency_code => 'amount_mismatch',
            $result->risky => 'gateway_risk',
            default => null,
        };

        $payment->forceFill([
            'gateway_txn' => $result->gatewayTxn,
            'method' => $result->method,
            'completed_at' => CarbonImmutable::now(),
        ]);

        if ($problem !== null) {
            $payment->forceFill(['status' => Payment::REVIEW, 'failure_code' => $problem])->save();
            $this->record($payment, 'payments.held', [
                'problem' => $problem,
                'expected' => [$payment->amount_minor, $payment->currency_code],
                'received' => [$result->amountMinor, $result->currency],
            ]);

            return Payment::REVIEW;
        }

        $payment->forceFill(['status' => Payment::SUCCEEDED, 'failure_code' => null])->save();
        $this->fulfiller->fulfil($payment);
        $this->record($payment, 'payments.succeeded', ['method' => $payment->method]);

        return Payment::SUCCEEDED;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function record(Payment $payment, string $action, array $extra): void
    {
        $this->audit->record(
            action: $action,
            target: $payment,
            new: [
                'gateway' => $payment->gateway,
                'purpose' => $payment->purpose,
                'amount_minor' => $payment->amount_minor,
                'currency' => $payment->currency_code,
                'invoice' => $payment->invoice_id,
                ...$extra,
            ],
            actor: null,
            organizationId: $payment->organization_id,
            partnerId: $payment->partner_id,
        );
    }

    private function event(PaymentGateway $gateway, string $kind, GatewayResult $result): GatewayEvent
    {
        $find = fn () => GatewayEvent::query()->where('gateway', $gateway->key())->where('event_ref', $result->eventRef)->first();

        $existing = $find();
        if ($existing !== null) {
            return $existing;
        }

        try {
            $event = new GatewayEvent;
            $event->forceFill([
                'gateway' => $gateway->key(),
                'kind' => $kind,
                'event_ref' => mb_substr($result->eventRef, 0, 150),
                'gateway_status' => $result->gatewayStatus,
                'payload' => $result->payload,
            ])->save();

            return $event;
        } catch (UniqueConstraintViolationException) {
            // The same message arrived twice at once: the other request handles it.
            return $find();
        }
    }
}
