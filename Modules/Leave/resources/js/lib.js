import { formatNumber } from '@/lib/format';

/**
 * Leave helpers for the screens. The server keeps every amount in half
 * days (2 = one day) and works out every balance and day count; these only
 * show what it sends.
 */

/** 5 -> 2.5 (days from half days). */
export function halvesToDays(halves) {
    return (Number(halves) || 0) / 2;
}

/** 5 -> "2.5" in the reader's digits. */
export function daysText(halves) {
    return formatNumber(halvesToDays(halves));
}

/** 2.5 -> 5 (half days from a number of days typed in; whole halves only). */
export function daysToHalves(days) {
    return Math.round((Number(days) || 0) * 2);
}

/** Badge tone per request status (with its word next to it, never colour alone). */
export function statusTone(status) {
    return { pending: 'warn', approved: 'ok', rejected: 'bad', cancelled: 'neutral' }[status] ?? 'neutral';
}

/** Every day of a month, "YYYY-MM-DD", for "YYYY-MM". */
export function monthDays(month) {
    const [year, number] = month.split('-').map(Number);
    const count = new Date(Date.UTC(year, number, 0)).getUTCDate();
    return Array.from({ length: count }, (_, index) => `${month}-${String(index + 1).padStart(2, '0')}`);
}

/** "2026-10" -> "2026-11" (step months, either way). */
export function shiftMonth(month, by) {
    const [year, number] = month.split('-').map(Number);
    const date = new Date(Date.UTC(year, number - 1 + by, 1));
    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
}

/** Whether a request covers a day (dates as "YYYY-MM-DD"). */
export function covers(request, date) {
    return request.from_date <= date && request.to_date >= date;
}

/** A new id for one sending of a request (the same id again is the same request). */
export function newOpId() {
    return `leave-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}
