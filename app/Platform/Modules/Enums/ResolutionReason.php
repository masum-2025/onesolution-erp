<?php

namespace App\Platform\Modules\Enums;

/**
 * Why a module is (not) enabled for an organization. Checked in this order;
 * the first failing check is reported.
 */
enum ResolutionReason: string
{
    case Enabled = 'enabled';
    case NotInPlan = 'not_in_plan';
    /** The platform does not let the organization's partner offer this module. */
    case NotOffered = 'not_offered';
    case SectorNotAllowed = 'sector_not_allowed';
    case ConsentMissing = 'consent_missing';
    case NotEnabled = 'not_enabled';
    case Disabled = 'disabled';
    case LockedDisabled = 'locked_disabled';
    case DependencyDisabled = 'dependency_disabled';
}
