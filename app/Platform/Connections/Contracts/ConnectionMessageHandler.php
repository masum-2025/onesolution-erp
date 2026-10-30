<?php

namespace App\Platform\Connections\Contracts;

use App\Platform\Connections\ConnectionDelivery;

/**
 * A kind of message one side of a business connection sends the other
 * (e.g. compliance.renewal_request, projects.quotation). The receiving module
 * declares it in its manifest ("connection_kinds") and writes what arrives
 * into its own tables here. It runs inside the receiver's own database and
 * must not read the sender's data: everything it needs is in the delivery.
 *
 * The same message may be delivered again after a failure halfway: receive()
 * must then find what it made the first time (by $delivery->messageId)
 * instead of making it twice.
 */
interface ConnectionMessageHandler
{
    /**
     * "{sending module}.{kind}", e.g. "compliance.renewal_request" (the client's
     * Compliance sends it; the provider's Projects declares this handler). The
     * sending module must be on at the sender, the declaring one at the receiver.
     */
    public function key(): string;

    /** Who receives it: ConnectionMessage::TO_CLIENT or ConnectionMessage::TO_PROVIDER. */
    public function direction(): string;

    /**
     * Store what arrived; return the id of the record made (shown to the
     * sender as "received as"), or null.
     */
    public function receive(ConnectionDelivery $delivery): ?string;
}
