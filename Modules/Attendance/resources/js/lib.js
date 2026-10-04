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

/** "23.810331" or "-0.5" -> 23810331 / -500000 (millionths of a degree, string maths); null when not a number. */
export function decimalToMicro(text) {
    const match = /^\s*(-?)(\d{1,3})(?:\.(\d{1,9}))?\s*$/.exec(String(text ?? ''));
    if (!match) return null;
    const fraction = (match[3] ?? '').padEnd(6, '0');
    // Beyond six decimals rounds half up on the seventh.
    let micro = Number(match[2]) * 1000000 + Number(fraction.slice(0, 6));
    if (fraction.length > 6 && Number(fraction[6]) >= 5) micro += 1;
    return match[1] === '-' && micro !== 0 ? -micro : micro;
}

/** 23810331 -> "23.810331". */
export function microToDecimal(micro) {
    const value = Math.abs(Math.trunc(Number(micro) || 0));
    const text = `${Math.floor(value / 1000000)}.${String(value % 1000000).padStart(6, '0')}`;
    return micro < 0 ? `-${text}` : text;
}

/** A browser position as the API wants it: whole millionths of a degree and metres. */
export function positionFix(coords) {
    return {
        latitude_micro: Math.round(coords.latitude * 1000000),
        longitude_micro: Math.round(coords.longitude * 1000000),
        accuracy_m: Math.max(0, Math.round(coords.accuracy ?? 0)),
    };
}

/** A first guess of an attendance machine file's columns, from its headings. */
export function guessDeviceColumns(columns) {
    const find = (...words) => columns.find((name) => words.some((word) => name.toLowerCase().includes(word))) ?? '';
    const date = find('date', 'তারিখ');
    // Machines often put date and time in one "Time" column (no date column then).
    const datetime = find('datetime', 'date time', 'date/time', 'checktime', 'check time', 'timestamp') || (date ? '' : find('time', 'সময়'));
    return {
        code: find('ac-no', 'ac no', 'no.', 'user id', 'userid', 'emp', 'code', 'badge', 'id'),
        datetime,
        date: datetime ? '' : date,
        time: datetime ? '' : find('time', 'সময়'),
    };
}
