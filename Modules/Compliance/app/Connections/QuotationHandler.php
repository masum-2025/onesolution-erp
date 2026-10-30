<?php

namespace Modules\Compliance\Connections;

use App\Platform\Connections\ConnectionDelivery;
use App\Platform\Connections\Contracts\ConnectionMessageHandler;
use App\Platform\Connections\Models\ConnectionMessage;
use Modules\Compliance\Services\Quotes;

/** A quotation from the connected provider: kept until the client accepts or declines it. */
final class QuotationHandler implements ConnectionMessageHandler
{
    public function __construct(private Quotes $quotes) {}

    public function key(): string
    {
        return Quotes::KIND;
    }

    public function direction(): string
    {
        return ConnectionMessage::TO_CLIENT;
    }

    public function receive(ConnectionDelivery $delivery): ?string
    {
        return $this->quotes->receive($delivery);
    }
}
