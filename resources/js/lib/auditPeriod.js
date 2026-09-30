/**
 * Periods for the audit log, its report and exports (Phase 9-1): whole days
 * as YYYY-MM-DD in the viewer's calendar; the server reads them in the
 * organization's time zone and includes the "to" day.
 */

/** The areas an audit entry belongs to (the part of its action before the dot). */
export const AUDIT_AREAS = ['auth', 'identity', 'membership', 'role', 'rule', 'module', 'organization', 'billing', 'payments', 'data', 'support', 'offline', 'partner', 'audit'];

export function isoDay(date) {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** The last `days` days up to and including today. */
export function lastDays(days, today = new Date()) {
    const from = new Date(today.getFullYear(), today.getMonth(), today.getDate() - (days - 1));
    return { from: isoDay(from), to: isoDay(today) };
}

/** Days in a period, both ends included; 0 when it is incomplete or backwards. */
export function periodLength({ from, to }) {
    if (!from || !to) return 0;
    const [a, b] = [from, to].map((value) => Date.UTC(...value.split('-').map((part, i) => Number(part) - (i === 1 ? 1 : 0))));
    return b < a ? 0 : Math.round((b - a) / 86400000) + 1;
}

/** Query parameters for the API, without empty values. */
export function auditQuery(filters) {
    return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== undefined && value !== null && value !== '' && value !== 'all'));
}
