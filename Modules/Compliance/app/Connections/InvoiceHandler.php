<?php

namespace Modules\Compliance\Connections;

use App\Platform\Connections\ConnectionDelivery;
use App\Platform\Connections\Contracts\ConnectionMessageHandler;
use App\Platform\Connections\Models\ConnectionMessage;
use Modules\Compliance\Services\ProviderInvoices;

/** An invoice from the connected provider (again when paid or voided): the client's copy. */
final class InvoiceHandler implements ConnectionMessageHandler
{
    public function __construct(private ProviderInvoices $invoices) {}

    public function key(): string
    {
        return ProviderInvoices::KIND;
    }

    public function direction(): string
    {
        return ConnectionMessage::TO_CLIENT;
    }

    public function receive(ConnectionDelivery $delivery): ?string
    {
        return $this->invoices->receive($delivery);
    }
}
