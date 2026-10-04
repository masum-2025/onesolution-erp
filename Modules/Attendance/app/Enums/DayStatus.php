<?php

namespace Modules\Attendance\Enums;

/** How an employee's day went. */
enum DayStatus: string
{
    /** On time (within the grace minutes). */
    case Present = 'present';
    case Late = 'late';
    /** Later than the half-day minutes. */
    case HalfDay = 'half_day';
    /** A working day that is over, with no punch. */
    case Absent = 'absent';
    /** Checked in, never out (the day is over). */
    case Incomplete = 'incomplete';
    /** A working day not over yet, no punch so far. */
    case Pending = 'pending';
    case Weekend = 'weekend';
    case Holiday = 'holiday';
    /** No shift rostered and no punch. */
    case NoShift = 'no_shift';

    /** Counts as a day at work. */
    public function attended(): bool
    {
        return in_array($this, [self::Present, self::Late, self::HalfDay, self::Incomplete], true);
    }
}
