<?php

namespace App\Platform\Portal\Contracts;

use App\Platform\Portal\PortalSubject;

/**
 * Optional for a PortalSubjectProvider: the module has its own portal screen
 * for the record (e.g. a customer's invoices), opened from the portal home
 * instead of the plain list of details.
 */
interface PortalSubjectPage
{
    /** A path in the app ("/portal/..."), or null to use the plain details page. */
    public function portalPath(PortalSubject $subject): ?string;
}
