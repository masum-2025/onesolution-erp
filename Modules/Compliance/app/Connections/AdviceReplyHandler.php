<?php

namespace Modules\Compliance\Connections;

use App\Platform\Connections\ConnectionDelivery;
use App\Platform\Connections\Contracts\ConnectionMessageHandler;
use App\Platform\Connections\Models\ConnectionMessage;
use Modules\Compliance\Services\PaymentAdvices;

/** The provider's answer to a payment the client told it about: recorded, or not received. */
final class AdviceReplyHandler implements ConnectionMessageHandler
{
    public function __construct(private PaymentAdvices $advices) {}

    public function key(): string
    {
        return PaymentAdvices::REPLY;
    }

    public function direction(): string
    {
        return ConnectionMessage::TO_CLIENT;
    }

    public function receive(ConnectionDelivery $delivery): ?string
    {
        return $this->advices->receiveReply($delivery);
    }
}
