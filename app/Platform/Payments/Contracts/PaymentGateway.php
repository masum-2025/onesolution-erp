<?php

namespace App\Platform\Payments\Contracts;

use App\Platform\Payments\CallbackUrls;
use App\Platform\Payments\GatewayResult;
use App\Platform\Payments\GatewaySession;
use App\Platform\Payments\Models\Payment;
use App\Platform\Payments\PaymentCustomer;
use Illuminate\Http\Request;

/**
 * An online payment gateway. The gateway holds card and wallet details;
 * we only ever see its references. What a payment is worth is fixed by the
 * server before the gateway is called, and a payment only counts once the
 * gateway itself confirms it (never on the browser's word).
 */
interface PaymentGateway
{
    /** The key used in rules and URLs, e.g. "sslcommerz". */
    public function key(): string;

    /** Configured and allowed to take payments here. */
    public function isAvailable(): bool;

    public function supportsCurrency(string $currency): bool;

    /** Takes test payments only (a sandbox): the page says so, so nobody thinks they paid. */
    public function isTestMode(): bool;

    /**
     * Opens a hosted payment page for the payment.
     *
     * @throws \App\Platform\Payments\Exceptions\PaymentException when the gateway refuses or cannot be reached
     */
    public function start(Payment $payment, PaymentCustomer $customer, CallbackUrls $urls): GatewaySession;

    /**
     * What a message from the gateway (server notification or the browser
     * coming back) says, after checking it with the gateway itself. null when
     * the message is not genuine or names no transaction.
     */
    public function resolve(Request $request): ?GatewayResult;

    /** The payment's current state, asked from the gateway by our own id. */
    public function lookup(Payment $payment): ?GatewayResult;
}
