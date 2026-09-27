<?php

namespace App\Platform\Payments;

/**
 * A payment's state as the gateway itself confirmed it.
 */
final readonly class GatewayResult
{
    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const PENDING = 'pending';

    /**
     * @param  string  $paymentId  Our payment id, as the gateway echoes it back.
     * @param  string  $eventRef  Unique per gateway message, so a repeat is applied once.
     * @param  array<string, mixed>  $payload  What the gateway sent, without secrets or card details.
     */
    public function __construct(
        public string $paymentId,
        public string $status,
        public string $eventRef,
        public string $gatewayStatus,
        public ?int $amountMinor = null,
        public ?string $currency = null,
        public ?string $gatewayTxn = null,
        public ?string $method = null,
        // The gateway flags the transaction for review (fraud checks): hold, do not unlock.
        public bool $risky = false,
        public array $payload = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->status === self::SUCCEEDED;
    }
}
