<?php

namespace Modules\Projects\Connections;

use App\Platform\Connections\ConnectionDelivery;
use App\Platform\Connections\Contracts\ConnectionMessageHandler;
use App\Platform\Connections\Models\ConnectionMessage;
use Modules\Projects\Services\Inbox;

/** A connected client's renewal request (its Compliance sends it): a new request in the provider's Projects. */
final class RenewalRequestHandler implements ConnectionMessageHandler
{
    public function __construct(private Inbox $inbox) {}

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
        return $this->inbox->receive($delivery);
    }
}
