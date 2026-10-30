<?php

namespace Modules\Compliance\Connections;

use App\Platform\Connections\ConnectionDelivery;
use App\Platform\Connections\Contracts\ConnectionMessageHandler;
use App\Platform\Connections\Models\ConnectionMessage;
use Modules\Compliance\Services\Results;

/** An audit result from the provider for a shared factory: proposed until the client accepts it. */
final class AuditResultHandler implements ConnectionMessageHandler
{
    public function __construct(private Results $results) {}

    public function key(): string
    {
        return Results::KIND;
    }

    public function direction(): string
    {
        return ConnectionMessage::TO_CLIENT;
    }

    public function receive(ConnectionDelivery $delivery): ?string
    {
        return $this->results->receive($delivery);
    }
}
