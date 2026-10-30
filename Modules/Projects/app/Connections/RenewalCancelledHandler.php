<?php

namespace Modules\Projects\Connections;

use App\Platform\Connections\ConnectionDelivery;
use App\Platform\Connections\Contracts\ConnectionMessageHandler;
use App\Platform\Connections\Models\ConnectionMessage;
use Modules\Projects\Services\Inbox;

/** The client took its renewal request back. */
final class RenewalCancelledHandler implements ConnectionMessageHandler
{
    public function __construct(private Inbox $inbox) {}

    public function key(): string
    {
        return 'compliance.renewal_cancelled';
    }

    public function direction(): string
    {
        return ConnectionMessage::TO_PROVIDER;
    }

    public function receive(ConnectionDelivery $delivery): ?string
    {
        return $this->inbox->receiveCancel($delivery);
    }
}
