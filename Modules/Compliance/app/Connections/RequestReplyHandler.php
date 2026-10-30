<?php

namespace Modules\Compliance\Connections;

use App\Platform\Connections\ConnectionDelivery;
use App\Platform\Connections\Contracts\ConnectionMessageHandler;
use App\Platform\Connections\Models\ConnectionMessage;
use Modules\Compliance\Services\Renewals;

/** The provider's answer to a renewal request: accepted (with a planned day), declined or done. */
final class RequestReplyHandler implements ConnectionMessageHandler
{
    public function __construct(private Renewals $renewals) {}

    public function key(): string
    {
        return 'projects.request_reply';
    }

    public function direction(): string
    {
        return ConnectionMessage::TO_CLIENT;
    }

    public function receive(ConnectionDelivery $delivery): ?string
    {
        return $this->renewals->receiveReply($delivery);
    }
}
