<?php

namespace App\Platform\Payments;

use App\Platform\Payments\Contracts\GatewayDriver;
use App\Platform\Payments\Contracts\PaymentGateway;
use App\Platform\Payments\Models\MerchantAccount;
use App\Platform\Payments\Models\Payment;
use App\Platform\Rules\RuleContext;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Scopes\OrganizationScope;

/**
 * The gateway drivers this build has, and whose account a payment uses:
 *
 * - the platform's own account (config), for paying us: offered for the
 *   payer's country (rule billing.payment_gateways), configured here, and
 *   able to take the currency;
 * - a client's merchant account (Phase 6), for its customers paying it.
 */
class GatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    /** @var array<string, GatewayDriver> */
    private array $drivers = [];

    public function __construct(private RuleResolver $rules) {}

    /** The platform's own account at a gateway. */
    public function register(PaymentGateway $gateway): void
    {
        $this->gateways[$gateway->key()] = $gateway;
    }

    public function registerDriver(GatewayDriver $driver): void
    {
        $this->drivers[$driver->key()] = $driver;
    }

    /** The platform's own account. */
    public function get(string $key): ?PaymentGateway
    {
        return $this->gateways[$key] ?? null;
    }

    public function driver(string $key): ?GatewayDriver
    {
        return $this->drivers[$key] ?? null;
    }

    /**
     * @return list<PaymentGateway>
     */
    public function available(RuleContext $context, string $currency): array
    {
        $offered = (array) $this->rules->get('billing.payment_gateways', $context);

        return array_values(array_filter(
            array_map(fn ($key) => $this->gateways[$key] ?? null, $offered),
            fn (?PaymentGateway $gateway) => $gateway !== null && $gateway->isAvailable() && $gateway->supportsCurrency($currency),
        ));
    }

    /**
     * A client's merchant account, with its approved credentials (or the
     * change waiting for approval, to test it). null when there are none.
     */
    public function forAccount(MerchantAccount $account, bool $pending = false): ?PaymentGateway
    {
        $driver = $this->drivers[$account->gateway] ?? null;
        $credentials = $pending ? $account->pending_credentials : $account->credentials;
        $mode = $pending ? $account->pending_mode : $account->mode;

        return $driver === null || $credentials === null || $mode === null ? null : $driver->make($credentials, $mode);
    }

    /**
     * The account a payment was made to: its merchant account, or the platform's.
     */
    public function forPayment(Payment $payment): ?PaymentGateway
    {
        if ($payment->merchant_account_id === null) {
            return $this->get($payment->gateway);
        }

        $account = MerchantAccount::query()->withoutGlobalScope(OrganizationScope::class)->find($payment->merchant_account_id);

        return $account === null || $account->gateway !== $payment->gateway ? null : $this->forAccount($account);
    }
}
