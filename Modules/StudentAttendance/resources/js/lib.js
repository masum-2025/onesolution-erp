/** The states in the order one tap moves through them. */
export const STATUSES = ['present', 'absent', 'late', 'leave'];

/** The next state after a tap: present → absent → late → leave → present. */
export function nextStatus(status) {
    return STATUSES[(STATUSES.indexOf(status) + 1) % STATUSES.length];
}

/** State → tone (colour is never alone: every state also shows its letter or word). */
export function statusTone(status) {
    return { present: 'ok', absent: 'bad', late: 'warn', leave: 'brand' }[status] ?? 'neutral';
}

/** How many students are in each state. */
export function countStates(students) {
    const counts = { present: 0, absent: 0, late: 0, leave: 0 };
    for (const student of students) counts[student.status] = (counts[student.status] ?? 0) + 1;
    return counts;
}

/** A day moved by some days, as YYYY-MM-DD (dates only, no time zones). */
export function addDays(date, days) {
    const [y, m, d] = date.split('-').map(Number);
    const moved = new Date(Date.UTC(y, m - 1, d + days));
    return moved.toISOString().slice(0, 10);
}

/** Every day of a month ("2026-03") as YYYY-MM-DD. */
export function monthDays(month) {
    const [y, m] = month.split('-').map(Number);
    const last = new Date(Date.UTC(y, m, 0)).getUTCDate();
    return Array.from({ length: last }, (_, index) => `${month}-${String(index + 1).padStart(2, '0')}`);
}

/** A month moved by some months ("2026-03" + 1 → "2026-04"). */
export function addMonths(month, months) {
    const [y, m] = month.split('-').map(Number);
    const moved = new Date(Date.UTC(y, m - 1 + months, 1));
    return moved.toISOString().slice(0, 7);
}

/** The period running at a time of day ("HH:MM"), else the next to come, else the last. */
export function periodAt(periods, time) {
    const timed = periods.filter((period) => period.starts_at);
    return timed.find((period) => period.starts_at <= time && (!period.ends_at || time < period.ends_at))
        ?? timed.find((period) => period.starts_at > time)
        ?? periods.at(-1)
        ?? null;
}

/** ISO weekday name as the weekend rule writes it ("fri") for a YYYY-MM-DD. */
export function weekdayKey(date) {
    const [y, m, d] = date.split('-').map(Number);
    return ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'][new Date(Date.UTC(y, m - 1, d)).getUTCDay()];
}

/** Whether a holiday covers a day for a class. */
export function holidayOn(holidays, date, levelId = null) {
    return holidays.find((holiday) => holiday.starts_on <= date && date <= holiday.ends_on && (!holiday.level_ids || (levelId && holiday.level_ids.includes(levelId)))) ?? null;
}
