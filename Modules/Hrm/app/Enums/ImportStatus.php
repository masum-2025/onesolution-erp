<?php

namespace Modules\Hrm\Enums;

enum ImportStatus: string
{
    /** Checked; waiting for someone to start it (or cancel it). */
    case Checked = 'checked';
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return in_array($this, [self::Checked, self::Queued, self::Running], true);
    }
}
