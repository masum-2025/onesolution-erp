/**
 * Attendance helpers for the screens: shift times as minutes after
 * midnight, durations, statuses and the days of a month. The server works
 * out every day; these only show and shape what it sends.
 */

/** 540 -> "09:00" (minutes after midnight, 24-hour). */
export function minutesToTime(minutes) {
    const value = ((Number(minutes) % 1440) + 1440) % 1440;
    return `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`;
}

/** "09:00" -> 540; null when it is not a time. */
export function timeToMinutes(text) {
    const match = /^([01]?\d|2[0-3]):([0-5]\d)$/.exec(String(text ?? '').trim());
    return match ? Number(match[1]) * 60 + Number(match[2]) : null;
}

/** A shift's hours: { from: "22:00", to: "06:00", nextDay: true }. */
export function shiftHours(shift) {
    return { from: minutesToTime(shift.start_minute), to: minutesToTime(shift.end_minute), nextDay: shift.end_minute <= shift.start_minute };
}

/** 425 -> { hours: 7, minutes: 5 }. */
export function durationParts(minutes) {
    const value = Math.max(0, Math.round(Number(minutes) || 0));
    return { hours: Math.floor(value / 60), minutes: value % 60 };
}

/** Badge tone per day status (with a text label next to it, never colour alone). */
export function dayTone(status) {
    return {
        present: 'ok',
        late: 'warn',
        half_day: 'warn',
        incomplete: 'warn',
        absent: 'bad',
        pending: 'neutral',
        weekend: 'outline',
        holiday: 'outline',
        no_shift: 'neutral',
    }[status] ?? 'neutral';
}

/** One letter-ish short mark for the month grid ("P", "L", "A"…), from the translated short labels. */
export const STATUSES = ['present', 'late', 'half_day', 'incomplete', 'absent', 'pending', 'weekend', 'holiday', 'no_shift'];

/** The plain dates of a month ("2026-10") as "YYYY-MM-DD", up to `until` when given. */
export function monthDates(month, until = null) {
    const [year, number] = month.split('-').map(Number);
    const last = new Date(Date.UTC(year, number, 0)).getUTCDate();
    const dates = [];
    for (let day = 1; day <= last; day++) {
        const date = `${month}-${String(day).padStart(2, '0')}`;
        if (until && date > until) break;
        dates.push(date);
    }
    return dates;
}

/** The month before or after "YYYY-MM". */
export function shiftMonth(month, by) {
    const [year, number] = month.split('-').map(Number);
    const date = new Date(Date.UTC(year, number - 1 + by, 1));
    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
}

/** Now in a timezone as the value of a datetime-local field ("2026-10-14T09:05"). */
export function localDateTime(timeZone, now = new Date()) {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-CA', { timeZone: timeZone || 'UTC', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
            .formatToParts(now)
            .map((part) => [part.type, part.value]),
    );
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}

/** Days of an employee keyed by date, for the month grid: { employeeId: { date: day } }. */
export function dayGrid(days) {
    const grid = {};
    for (const day of days ?? []) {
        grid[day.employee_id] ??= {};
        grid[day.employee_id][day.work_date] = day;
    }
    return grid;
}

/** Counts of each status among days (for summary chips). */
export function statusCounts(days) {
    const counts = Object.fromEntries(STATUSES.map((status) => [status, 0]));
    for (const day of days ?? []) counts[day.status] = (counts[day.status] ?? 0) + 1;
    return counts;
}

/** What the big button does next: check in when nothing today or already out, else check out. */
export function nextPunch(today) {
    if (!today?.first_in_at) return 'in';
    return today.last_out_at ? 'in' : 'out';
}
