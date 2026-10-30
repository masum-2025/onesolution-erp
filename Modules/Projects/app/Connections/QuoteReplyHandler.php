<?php

namespace Modules\Projects\Connections;

use App\Platform\Connections\ConnectionDelivery;
use App\Platform\Connections\Contracts\ConnectionMessageHandler;
use App\Platform\Connections\Models\ConnectionMessage;
use Modules\Projects\Services\QuoteExchange;

/** The connected client's answer to a quotation: applied in CRM. */
final class QuoteReplyHandler implements ConnectionMessageHandler
{
    public function __construct(private QuoteExchange $exchange) {}

    public function key(): string
    {
        return 'compliance.quote_reply';
    }

    public function direction(): string
    {
        return ConnectionMessage::TO_PROVIDER;
    }

    public function receive(ConnectionDelivery $delivery): ?string
    {
        return $this->exchange->receiveReply($delivery);
    }
}
