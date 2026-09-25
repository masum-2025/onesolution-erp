<?php

namespace App\Platform\Tenancy\Enums;

/**
 * How far a membership reaches. "descendants" on a group membership gives
 * read access to every company under the group (group admin). Phase 4 maps
 * this onto role permissions.
 */
enum AccessScope: string
{
    case Own = 'own';
    case Descendants = 'descendants';
}
