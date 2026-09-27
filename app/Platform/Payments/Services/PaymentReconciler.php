<?php

namespace App\Platform\Payments\Services;

use App\Platform\Payments\GatewayRegistry;
use App\Platform\Payments\Models\GatewayEvent;
use App\Platform\Payments\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Gateway notices can be late or lost (and never reach a laptop in
 * development), so open payments are also asked about by our own id:
 * from the status page while someone waits, and by a scheduled sweep that
 * gives up payments past their expiry. A late success still applies.
 */
class PaymentReconciler
{
    /** Seconds before asking about a new payment, and between two asks. */
    private const WAIT_SECONDS = 10;

    public function __construct(private GatewayRegistry $gateways, private PaymentConfirmer $confirmer) {}

    public function check(Payment $payment): Payment
    {
        if (! $payment->isPending() || $payment->created_at->greaterThan(CarbonImmutable::now()->subSeconds(self::WAIT_SECONDS))) {
            return $payment;
        }

        if (! Cache::add("payments:lookup:{$payment->getKey()}", true, self::WAIT_SECONDS)) {
            return $payment;
        }

        return $this->lookup($payment) ?? $payment;
    }

    /**
     * @return array{checked: int, succeeded: int, expired: int}
     */
    public function sweep(CarbonImmutable $now): array
    {
        $counts = ['checked' => 0, 'succeeded' => 0, 'expired' => 0];

        $open = Payment::query()
            ->where('status', Payment::PENDING)
            ->where('created_at', '<', $now->subMinutes(2))
            ->lazyById();

        foreach ($open as $payment) {
            $counts['checked']++;
            $payment = $this->lookup($payment) ?? $payment;

            if ($payment->isSucceeded()) {
                $counts['succeeded']++;
            } elseif ($payment->isPending() && $payment->expires_at->lessThan($now)) {
                $this->confirmer->expire($payment);
                $counts['expired']++;
            }
        }

        return $counts;
    }

    private function lookup(Payment $payment): ?Payment
    {
        $gateway = $this->gateways->get($payment->gateway);
        if ($gateway === null || ! $gateway->isAvailable()) {
            return null;
        }

        $result = $gateway->lookup($payment);

        return $result === null ? null : $this->confirmer->apply($gateway, GatewayEvent::LOOKUP, $result);
    }
}
