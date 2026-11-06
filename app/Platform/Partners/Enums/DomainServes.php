<?php

namespace App\Platform\Partners\Enums;

/** What a verified domain opens. */
enum DomainServes: string
{
    /** The app: sign-in, the partner console, clients' work. */
    case App = 'app';

    /** The partner's company website (MKT-3c); never the app. Partner level only. */
    case Website = 'website';
}
