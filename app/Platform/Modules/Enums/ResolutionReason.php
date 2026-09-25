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
    case SectorNotAllowed = 'sector_not_allowed';
    case ConsentMissing = 'consent_missing';
    case NotEnabled = 'not_enabled';
    case Disabled = 'disabled';
    case LockedDisabled = 'locked_disabled';
    case DependencyDisabled = 'dependency_disabled';
}
