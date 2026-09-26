<?php

namespace App\Platform\SupportAccess\Enums;

/**
 * How urgent a support request is; clients may auto-approve some levels
 * (rule support.auto_approve_severities).
 */
enum Severity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';
}
