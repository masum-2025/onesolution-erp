<?php

namespace App\Platform\Partners\Enums;

enum DomainStatus: string
{
    /** Added; waiting for the DNS TXT record. Resolves to nothing. */
    case Pending = 'pending';

    /** Ownership verified: the host serves the partner (or client). */
    case Active = 'active';

    /** Turned off by the partner; resolves to nothing. */
    case Disabled = 'disabled';
}
