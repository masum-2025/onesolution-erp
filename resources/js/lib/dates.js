/**
 * Plain days ("YYYY-MM-DD") on the reader's own clock: a date field's
 * "today" is the person's day, not UTC's (the server checks the company's).
 */

/** Today, or n days from it: localToday() -> "2026-11-03", localToday(-7) a week back. */
export function localToday(days = 0, now = new Date()) {
    const day = new Date(now.getFullYear(), now.getMonth(), now.getDate() + days);
    return `${day.getFullYear()}-${String(day.getMonth() + 1).padStart(2, '0')}-${String(day.getDate()).padStart(2, '0')}`;
}
