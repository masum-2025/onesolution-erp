<?php

namespace Modules\Projects\Connections;

use App\Platform\Connections\ConnectionDelivery;
use App\Platform\Connections\Contracts\ConnectionMessageHandler;
use App\Platform\Connections\Models\ConnectionMessage;
use Modules\Projects\Services\ClientPayments;

/** A connected client says it paid a project's invoice: kept to check and record. */
final class PaymentAdviceHandler implements ConnectionMessageHandler
{
    public function __construct(private ClientPayments $payments) {}

    public function key(): string
    {
        return ClientPayments::KIND;
    }

    public function direction(): string
    {
        return ConnectionMessage::TO_PROVIDER;
    }

    public function receive(ConnectionDelivery $delivery): ?string
    {
        return $this->payments->receive($delivery);
    }
}
