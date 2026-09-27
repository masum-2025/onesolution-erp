<?php

namespace App\Platform\Payments;

use App\Platform\Payments\Contracts\PaymentGateway;
use App\Platform\Rules\RuleContext;
use App\Platform\Rules\RuleResolver;

/**
 * The gateway drivers this build has, and which of them a payer may use:
 * offered for their country (rule billing.payment_gateways), configured
 * here, and able to take the currency.
 */
class GatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    public function __construct(private RuleResolver $rules) {}

    public function register(PaymentGateway $gateway): void
    {
        $this->gateways[$gateway->key()] = $gateway;
    }

    public function get(string $key): ?PaymentGateway
    {
        return $this->gateways[$key] ?? null;
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
}
