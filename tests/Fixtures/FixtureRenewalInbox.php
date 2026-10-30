<?php

namespace Tests\Fixtures;

use App\Platform\Connections\ConnectionDelivery;
use App\Platform\Connections\Contracts\ConnectionMessageHandler;
use App\Platform\Connections\Models\ConnectionMessage;

/**
 * Stand-in for the provider's Projects module (PRJ-1) receiving a client's
 * renewal request (compliance.renewal_request): keeps what arrived.
 */
class FixtureRenewalInbox implements ConnectionMessageHandler
{
    /** @var list<array{to: string, from: string, payload: array<string, mixed>}> */
    public static array $received = [];

    public function key(): string
    {
        return 'compliance.renewal_request';
    }

    public function direction(): string
    {
        return ConnectionMessage::TO_PROVIDER;
    }

    public function receive(ConnectionDelivery $delivery): ?string
    {
        self::$received[] = ['to' => $delivery->to->getKey(), 'from' => $delivery->fromName, 'payload' => $delivery->payload];

        return null;
    }
}
