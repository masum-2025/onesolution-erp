import { amountToMinor as readAmount } from '@/lib/numbers';

/**
 * Small helpers of the compliance screens. Days left come from the server
 * (worked out from the expiry day and the company's today); these only show them.
 */

export const STATES = ['expired', 'expiring', 'valid', 'unknown'];

/** How a payment was made (told to the provider). */
export const PAYMENT_METHODS = ['bank', 'cash', 'cheque', 'mobile', 'other'];

/** Typed money (the shared reader) -> minor units; null unless above zero (a payment is never nothing). */
export function amountToMinor(text, currency) {
    const minor = readAmount(text, currency);
    return minor > 0 ? minor : null;
}

/** Today on the reader's own clock, "YYYY-MM-DD" (not UTC). */
export function localToday(now = new Date()) {
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

/** Badge tone of a payment told to the provider (its word always shown next to it). */
export function adviceTone(status) {
    return { sent: 'warn', recorded: 'ok', not_received: 'bad' }[status] ?? 'neutral';
}

/** Badge tone of a state; its word is always shown next to it (never colour alone). */
export function stateTone(state) {
    return { valid: 'ok', expiring: 'warn', expired: 'bad', unknown: 'neutral' }[state] ?? 'neutral';
}

/** "YYYY-MM-DD" plus whole months (end of month kept: 31 Jan + 1 month = 28/29 Feb), minus one day: a certificate's last day. */
export function expiryFrom(lastAuditOn, months) {
    if (!lastAuditOn || !months) return '';
    const [year, month, day] = lastAuditOn.split('-').map(Number);
    const target = new Date(Date.UTC(year, month - 1 + months, 1));
    const lastDay = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();
    const result = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth(), Math.min(day, lastDay)));
    result.setUTCDate(result.getUTCDate() - 1);
    return result.toISOString().slice(0, 10);
}

/**
 * The grid of the register: one row per factory (in tree order), one column
 * per service that has a line or is chosen, each cell the line or null.
 */
export function grid(lines, factories, services, keepEmptyRows = true) {
    const used = new Set(lines.map((line) => line.service?.id));
    const columns = services.filter((service) => used.has(service.id));
    const byKey = new Map(lines.map((line) => [`${line.factory.id}|${line.service?.id}`, line]));
    const rows = factories.map((factory) => ({
        factory,
        cells: columns.map((service) => byKey.get(`${factory.id}|${service.id}`) ?? null),
    })).filter((row) => keepEmptyRows || row.cells.some(Boolean));
    return { columns, rows };
}

/** Badge tone of a renewal request's status (its word always shown next to it). */
export function renewalTone(status) {
    return { sent: 'warn', recorded: 'neutral', accepted: 'ok', declined: 'bad', cancelled: 'outline', done: 'ok' }[status] ?? 'neutral';
}

/**
 * What a register line shows about its renewal: results waiting first (they
 * need someone), then an open request's status; nothing once it is closed.
 */
export function renewalMark(line) {
    if (line?.results_pending > 0) return { key: 'results', tone: 'warn', count: line.results_pending };
    if (line?.renewal && ['sent', 'recorded', 'accepted'].includes(line.renewal.status)) return { key: line.renewal.status, tone: renewalTone(line.renewal.status), planned: line.renewal.planned_on };
    return null;
}

/** "mailto:" link with the prepared subject and text, for a provider not on the platform. */
export function mailtoLink(mail) {
    if (!mail) return '';
    // The address as it is (mail apps read "%40" badly), only its plain characters.
    return `mailto:${(mail.email ?? '').replace(/[^\w.@+-]/g, '')}?subject=${encodeURIComponent(mail.subject)}&body=${encodeURIComponent(mail.body)}`;
}

/** How many of each state, from the register's summary (a missing state is 0). */
export function counts(summary) {
    return Object.fromEntries(STATES.map((state) => [state, summary?.[state] ?? 0]));
}
